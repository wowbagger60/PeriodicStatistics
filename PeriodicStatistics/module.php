<?php declare(strict_types=1);

class PeriodicStatistics extends IPSModuleStrict
{
    private const STATUS_INVALID_CONFIGURATION = 201;
    private const STATUS_MISSING_VARIABLE = 202;
    private const TIMER_ROLLOVER = 'Rollover';
    private const TIMER_ARCHIVE = 'ArchiveRebuild';
    private const TIMER_REFRESH = 'Refresh';
    private const ARCHIVE_PAGE_SIZE = 5000;
    private const LOCK_TIMEOUT = 30000;
    private const ALLTIME = 'Alltime';
    private const ARCHIVE_PROGRESS_FIELDS = ['processed', 'cursor', 'periods', 'next', 'newest', 'archiveSeen'];
    private const TIME_WINDOW_ID_PATTERN = '/^tw_[0-9a-f]{12}$/';
    private const INDIVIDUAL_IDENT_PATTERN = '/^(\d+)_(.+)_(min|max|avga|avgt|delta|integral|mintime|maxtime)$/';
    // Metric => [config column, caption]; timestamps follow their extreme.
    private const INDIVIDUAL_METRICS = [
        'min'      => ['Min', 'Minimum'],
        'max'      => ['Max', 'Maximum'],
        'avga'     => ['AvgArithmetic', 'Average (arithmetic)'],
        'avgt'     => ['AvgTimeWeighted', 'Average (time-weighted)'],
        'delta'    => ['Delta', 'Difference'],
        'integral' => ['Integral', 'Integral'],
        'mintime'  => ['Timestamps', 'Time of minimum'],
        'maxtime'  => ['Timestamps', 'Time of maximum']
    ];

    // Nesting depth of the instance lock within the current PHP call.
    private int $lockDepth = 0;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('RefreshInterval', 15);
        $this->RegisterPropertyString('Variables', '[]');
        $this->RegisterPropertyString('TimeWindows', json_encode($this->CreateDefaultTimeWindows()));
        $this->RegisterPropertyBoolean('IndividualAlltime', false);

        $this->RegisterTimer(self::TIMER_ROLLOVER, 0, 'PERST_InternalTimer($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_ARCHIVE, 0, 'PERST_InternalArchiveTimer($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_REFRESH, 0, 'PERST_InternalRefreshTimer($_IPS[\'TARGET\']);');

        $this->RegisterAttributeString('RegisteredVariables', '[]');
        $this->RegisterAttributeString('State', '{}');
        $this->RegisterAttributeString('ConfigurationError', '');
        $this->RegisterAttributeString('ArchiveJobs', '{}');
        $this->RegisterAttributeString('ArchiveQueue', '[]');
        $this->RegisterAttributeString('GeneratedNames', '{}');
        $this->RegisterAttributeString('TimeWindowIDs', '[]');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            // Initialization continues on IPS_KERNELSTARTED, where pending archive jobs resume.
            return;
        }

        $this->Initialize(true);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Shows the effect of the saved configuration; unsaved changes count after applying.
        foreach ($form['elements'] as $index => $element) {
            if (($element['name'] ?? '') === 'IndividualCount') {
                $form['elements'][$index]['caption'] = sprintf(
                    $this->Translate('Individual variables currently created: %d'),
                    count($this->GetIndividualTargets())
                );
            }
        }

        foreach ($form['actions'] as $index => $action) {
            switch ($action['name'] ?? '') {
                case 'RebuildVariable':
                    $form['actions'][$index]['options'] = $this->GetRebuildOptions();
                    break;
                case 'Jobs':
                    $form['actions'][$index]['values'] = $this->GetJobListValues();
                    break;
            }
        }

        $error = $this->ReadConfigurationError();
        if ($error !== '') {
            array_unshift($form['elements'], [
                'type'    => 'Label',
                'caption' => $error
            ]);
        }

        return json_encode($form);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Rebuild':
                $this->RebuildFromForm((int) $Value);
                return;
        }
        throw new InvalidArgumentException('Unknown action.');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->Initialize(false);
                return;

            case VM_UPDATE:
                $this->HandleVariableUpdate((int) $SenderID, $Data);
                return;

            case VM_DELETE:
                $this->HandleVariableDelete((int) $SenderID);
                return;
        }
    }

    public function GetValuesByVariable(int $VariableID): array
    {
        $state = $this->ReadState();

        if ($VariableID !== 0) {
            $this->EnsureValidSourceVariable($VariableID);
            return isset($state[(string) $VariableID])
                ? $this->CreatePublicData($state[(string) $VariableID])
                : [];
        }

        $result = [];
        foreach ($this->GetConfiguredVariableIDs() as $variableID) {
            if (isset($state[(string) $variableID])) {
                $result[$variableID] = $this->CreatePublicData($state[(string) $variableID]);
            }
        }
        return $result;
    }

    public function GetValuesByPeriod(string $PeriodName): array
    {
        if ($PeriodName !== '' && $this->GetPeriodDefinition($PeriodName) === null) {
            throw new InvalidArgumentException('Unknown period: ' . $PeriodName);
        }

        $state = $this->ReadState();
        $result = [];
        foreach ($this->GetConfiguredVariableIDs() as $variableID) {
            if (!isset($state[(string) $variableID])) {
                continue;
            }
            $data = $this->CreatePublicData($state[(string) $variableID]);
            if ($PeriodName !== '') {
                foreach ($data as $name => $periodData) {
                    if (strcasecmp((string) $name, $PeriodName) === 0) {
                        $result[$variableID] = $periodData;
                        break;
                    }
                }
            } else {
                foreach ($data as $name => $periodData) {
                    $result[$name][$variableID] = $periodData;
                }
            }
        }
        return $result;
    }

    public function InternalTimer(): void
    {
        $this->WithLock(function (): void {
            $this->SynchronizeCurrentValues($this->GetActiveVariableIDs());
        });
        $this->ScheduleNextRollover();
    }

    // Republishes time-weighted averages and integrals, which change with time even without new values.
    public function InternalRefreshTimer(): void
    {
        $this->WithLock(function (): void {
            $state = $this->ReadState();
            foreach ($this->GetActiveVariableIDs() as $variableID) {
                if (isset($state[(string) $variableID])) {
                    $this->PublishVariable($variableID, $state[(string) $variableID]);
                }
            }
        });
    }

    public function ProcessValue(int $VariableID, string $PeriodName, float $Value, int $Timestamp): bool
    {
        $this->EnsureValidSourceVariable($VariableID);

        if ($Timestamp <= 0 || $Timestamp > time()) {
            return false;
        }

        $period = $this->GetPeriodDefinition($PeriodName);
        if ($period === null) {
            throw new InvalidArgumentException('Unknown period: ' . $PeriodName);
        }

        if ($period['kind'] !== 'custom' || $period['repeat']) {
            $bounds = $this->GetPeriodBounds($period, $Timestamp);
            $currentBounds = $this->GetPeriodBounds($period, time());
            if ($bounds === null || $currentBounds === null || $bounds[0] !== $currentBounds[0]) {
                return false;
            }
        }

        return $this->WithLock(function () use ($VariableID, $period, $Value, $Timestamp): bool {
            $state = $this->ReadState();
            $key = (string) $VariableID;
            $variableState = $state[$key] ?? ['periods' => []];
            if (!$this->ApplyValue($variableState, $VariableID, [$period], $Value, $Timestamp, true)) {
                return false;
            }
            $state[$key] = $variableState;
            $this->WriteState($state);
            $this->PublishVariable($VariableID, $variableState);
            return true;
        });
    }

    public function ResetValue(int $VariableID, string $PeriodName, string $Extreme = ''): bool
    {
        $this->EnsureValidSourceVariable($VariableID);

        $period = $this->GetPeriodDefinition($PeriodName);
        if ($period === null) {
            throw new InvalidArgumentException('Unknown period: ' . $PeriodName);
        }

        $extreme = strtolower(trim($Extreme));
        if (!in_array($extreme, ['', 'min', 'max', 'aggregates'], true)) {
            throw new InvalidArgumentException('Extreme must be "min", "max", "aggregates" or empty.');
        }

        return $this->WithLock(function () use ($VariableID, $period, $extreme): bool {
            $state = $this->ReadState();
            $key = (string) $VariableID;
            $periodKey = $period['key'];
            if (!isset($state[$key]['periods'][$periodKey])) {
                return false;
            }

            $entry = &$state[$key]['periods'][$periodKey];
            if ($extreme === '' || $extreme === 'min') {
                $entry['min'] = null;
            }
            if ($extreme === '' || $extreme === 'max') {
                $entry['max'] = null;
            }
            if ($extreme === '' || $extreme === 'aggregates') {
                // A running period restarts averages, difference and integral with the current value;
                // a completed one has none left.
                $now = time();
                $entry = array_merge($entry, (int) $entry['periodEnd'] > $now
                    ? $this->CreateAggregates($this->NormalizeValue($VariableID, (float) GetValue($VariableID)), $now)
                    : $this->CreateEmptyAggregates());
            }
            unset($entry);

            $this->WriteState($state);
            $this->PublishVariable($VariableID, $state[$key]);
            return true;
        });
    }

    // Archive rebuilds are asynchronous. Jobs survive timer ticks and Symcon restarts,
    // but applying the configuration aborts them.
    public function RebuildFromArchive(int $VariableID): string
    {
        return $this->StartArchiveJob($VariableID);
    }

    public function GetJobStatus(string $Token): array
    {
        $jobs = $this->ReadArchiveJobs();
        if (!isset($jobs[$Token])) {
            return ['status' => 'unknown', 'variable' => 0, 'processed' => 0, 'message' => ''];
        }
        return $this->PublicJob($Token, $jobs[$Token]);
    }

    public function GetJobs(): array
    {
        $result = [];
        foreach ($this->ReadArchiveJobs() as $token => $job) {
            $result[] = $this->PublicJob((string) $token, $job);
        }
        return $result;
    }

    public function InternalArchiveTimer(): void
    {
        $work = $this->WithLock(function (): ?array {
            $jobs = $this->ReadArchiveJobs();
            foreach ($jobs as $token => $job) {
                if (in_array($job['status'], ['finished', 'failed'], true) && time() - ($job['completed'] ?? time()) > 3600) {
                    unset($jobs[$token]);
                }
            }
            $queue = $this->ReadArchiveQueue();
            while (count($queue) > 0 && !isset($jobs[$queue[0]])) {
                array_shift($queue);
            }
            $token = $queue[0] ?? null;
            if ($token !== null) {
                $jobs[$token]['status'] = 'running';
            }
            $this->WriteArchiveJobs($jobs);
            $this->WriteArchiveQueue($queue);
            if ($token === null) {
                $this->SetTimerInterval(self::TIMER_ARCHIVE, 0);
                return null;
            }
            return ['token' => $token, 'job' => $jobs[$token]];
        });
        if ($work === null) {
            return;
        }

        // The archive is read without holding the lock, so live updates are not delayed.
        $job = $work['job'];
        $done = false;
        $error = null;
        try {
            $done = $this->ProcessArchivePage($job);
        } catch (Throwable $exception) {
            $error = $exception;
        }

        $this->WithLock(function () use ($work, $job, $done, $error): void {
            $token = $work['token'];
            $jobs = $this->ReadArchiveJobs();
            $current = $jobs[$token] ?? null;
            // Discard the page if the job was aborted or another timer run advanced it meanwhile.
            if ($current === null || $current['status'] !== 'running'
                || $current['cursor'] !== $work['job']['cursor'] || $current['processed'] !== $work['job']['processed']) {
                return;
            }
            foreach (self::ARCHIVE_PROGRESS_FIELDS as $field) {
                $current[$field] = $job[$field];
            }
            if ($error === null && $done) {
                try {
                    $this->CommitArchiveJob($current);
                    $current['status'] = 'finished';
                } catch (Throwable $exception) {
                    $error = $exception;
                }
            }
            if ($error !== null) {
                $current['status'] = 'failed';
                $current['message'] = $error->getMessage();
                $this->ReplayFailedRebuild($current);
                $this->SendDebug('ArchiveRebuild', $error->getMessage(), 0);
            }
            if ($current['status'] !== 'running') {
                $current['completed'] = time();
                $current['live'] = [];
                $this->WriteArchiveQueue(array_values(array_filter(
                    $this->ReadArchiveQueue(),
                    static fn($queued) => $queued !== $token
                )));
            }
            $jobs[$token] = $current;
            $this->WriteArchiveJobs($jobs);
            $this->SetTimerInterval(self::TIMER_ARCHIVE, count($this->ReadArchiveQueue()) > 0 ? 1000 : 0);
        });
        $this->UpdateJobList();
    }

    // Starts rebuilds for one or, with 0, all configured variables and reports the result in a popup.
    private function RebuildFromForm(int $variableID): void
    {
        $variableIDs = $variableID === 0 ? $this->GetActiveVariableIDs() : [$variableID];
        if (count($variableIDs) === 0) {
            $this->ShowRebuildResult($this->Translate('No variable to rebuild.'));
            return;
        }
        $started = 0;
        $errors = [];
        foreach ($variableIDs as $id) {
            try {
                $this->StartArchiveJob($id);
                ++$started;
            } catch (Throwable $exception) {
                $errors[] = $this->GetVariableCaption($id) . ': ' . $this->Translate($exception->getMessage());
            }
        }
        $message = sprintf($this->Translate('Rebuild started for %d variable(s).'), $started);
        if (count($errors) > 0) {
            $message .= "\n\n" . $this->Translate('Not started:') . "\n" . implode("\n", $errors);
        }
        $this->ShowRebuildResult($message);
    }

    // An echo from RequestAction appears as an error with file and line, so the form shows a popup instead.
    private function ShowRebuildResult(string $message): void
    {
        $this->UpdateFormField('RebuildResultText', 'caption', $message);
        $this->UpdateFormField('RebuildResult', 'visible', true);
    }

    private function GetRebuildOptions(): array
    {
        $options = [['caption' => $this->Translate('All variables'), 'value' => 0]];
        foreach ($this->GetActiveVariableIDs() as $variableID) {
            $options[] = ['caption' => $this->GetVariableCaption($variableID), 'value' => $variableID];
        }
        return $options;
    }

    private function GetJobListValues(): array
    {
        $values = [];
        foreach ($this->ReadArchiveJobs() as $job) {
            $values[] = [
                'Variable'  => $this->GetVariableCaption((int) $job['variable']),
                'Status'    => $this->Translate(ucfirst((string) $job['status'])),
                'Processed' => (int) $job['processed'],
                'Message'   => $job['message'] !== '' ? $this->Translate((string) $job['message']) : ''
            ];
        }
        return $values;
    }

    // Refreshes the job list of an open configuration form.
    private function UpdateJobList(): void
    {
        $this->UpdateFormField('Jobs', 'values', json_encode($this->GetJobListValues()));
    }

    private function GetVariableCaption(int $variableID): string
    {
        return IPS_VariableExists($variableID) ? sprintf('%s (%d)', IPS_GetLocation($variableID), $variableID) : (string) $variableID;
    }

    private function Initialize(bool $abortJobs): void
    {
        if ($this->EnsureTimeWindowIDs()) {
            return; // The configuration was rewritten and is applied again.
        }
        if ($abortJobs) {
            $this->WithLock(function (): void {
                $this->AbortArchiveJobs('Configuration was applied during archive processing.');
            });
        }
        $this->UnregisterVariableWatches();

        $error = $this->ValidateConfiguration();
        $this->WriteAttributeString('ConfigurationError', $error === null ? '' : json_encode($error));
        if ($error !== null && $error['fatal']) {
            $this->SetTimerInterval(self::TIMER_ROLLOVER, 0);
            $this->SetTimerInterval(self::TIMER_REFRESH, 0);
            $this->SetTimerInterval(self::TIMER_ARCHIVE, 0);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            return;
        }

        $variableIDs = $this->GetActiveVariableIDs();
        $this->RegisterVariableWatches($variableIDs);
        $this->WithLock(function () use ($variableIDs): void {
            $this->SynchronizeCurrentValues($variableIDs);
            $this->SynchronizeOutputVariables();
        });
        $this->ScheduleNextRollover();
        $this->UpdateRefreshTimer($variableIDs);
        $this->SetTimerInterval(self::TIMER_ARCHIVE, count($this->ReadArchiveQueue()) > 0 ? 1000 : 0);

        $this->SetStatus($error === null ? IS_ACTIVE : self::STATUS_MISSING_VARIABLE);
    }

    private function HandleVariableUpdate(int $variableID, array $data): void
    {
        if (!isset($data[1]) || !$data[1]) {
            return;
        }
        if (!is_int($data[0]) && !is_float($data[0])) {
            return;
        }
        if (!in_array($variableID, $this->GetConfiguredVariableIDs(), true) || !IPS_VariableExists($variableID)) {
            return;
        }

        // The first MessageSink parameter is a message counter, not a Unix timestamp.
        // VariableChanged is the actual timestamp of this value change.
        $timestamp = (int) IPS_GetVariable($variableID)['VariableChanged'];
        $value = (float) $data[0];

        $this->WithLock(function () use ($variableID, $value, $timestamp): void {
            if ($this->RecordRebuildLiveValue($variableID, $value, $timestamp)) {
                return;
            }
            $state = $this->ReadState();
            $key = (string) $variableID;
            $variableState = $state[$key] ?? ['periods' => []];
            $changed = $this->ApplyValue($variableState, $variableID, $this->GetPeriodDefinitions(), $value, $timestamp, true);
            $this->RememberLastValue($variableState, $variableID, $value, $timestamp);
            $state[$key] = $variableState;
            $this->WriteState($state);
            if ($changed) {
                $this->PublishVariable($variableID, $variableState);
            }
        });
    }

    // A deleted source only disables its own statistics; outputs and state stay until its row is removed.
    private function HandleVariableDelete(int $variableID): void
    {
        @$this->UnregisterMessage($variableID, VM_UPDATE);
        @$this->UnregisterMessage($variableID, VM_DELETE);
        @$this->UnregisterReference($variableID);

        $registered = json_decode($this->ReadAttributeString('RegisteredVariables'), true);
        $registered = is_array($registered) ? $registered : [];
        $this->WriteAttributeString('RegisteredVariables', json_encode(array_values(array_filter(
            $registered,
            static fn($id) => (int) $id !== $variableID
        ))));

        $this->WithLock(function () use ($variableID): void {
            $this->AbortArchiveJobs('The source variable was deleted.', $variableID);
        });
        $this->UpdateRefreshTimer($this->GetActiveVariableIDs());

        $this->WriteAttributeString('ConfigurationError', json_encode($this->CreateError('A configured variable does not exist.', false)));
        $this->SetStatus(self::STATUS_MISSING_VARIABLE);
    }

    private function StartArchiveJob(int $VariableID): string
    {
        $this->EnsureValidSourceVariable($VariableID);
        if (isset($this->GetRebuildingVariables()[$VariableID])) {
            throw new RuntimeException('A rebuild is already pending for this variable.');
        }
        $archives = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archives) === 0) {
            throw new RuntimeException('No archive control instance found.');
        }
        $archiveID = (int) $archives[0];
        if (!AC_GetLoggingStatus($archiveID, $VariableID)) {
            throw new RuntimeException('Archive logging is not enabled for this variable.');
        }
        $now = time();
        $periods = [];
        foreach ($this->GetPeriodDefinitions() as $period) {
            $bounds = $this->GetPeriodBounds($period, $now);
            if ($bounds !== null) {
                $periods[$period['key']] = $this->CreateArchivePeriod($bounds[0], $bounds[1]);
            } elseif ($period['kind'] === 'custom' && !$period['repeat']) {
                // A completed fixed window is also eligible for historical reconstruction.
                $anchor = $this->GetCustomAnchor($period);
                $end = $this->ShiftCustomBoundary($anchor, $period['type'], $period['duration'])->getTimestamp();
                if ($anchor->getTimestamp() < $now) {
                    $periods[$period['key']] = $this->CreateArchivePeriod($anchor->getTimestamp(), $end);
                }
            }
        }
        $start = min(array_column($periods, 'start'));
        $token = sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
        $job = ['status' => 'queued', 'variable' => $VariableID, 'processed' => 0, 'message' => '',
            'archive' => $archiveID, 'start' => $start, 'cursor' => $now, 'cutoff' => $now,
            'periods' => $periods, 'next' => null, 'newest' => null, 'live' => [], 'created' => $now, 'archiveSeen' => []];
        $this->WithLock(function () use ($token, $job): void {
            $jobs = $this->ReadArchiveJobs();
            $jobs[$token] = $job;
            $this->WriteArchiveJobs($jobs);
            $queue = $this->ReadArchiveQueue();
            $queue[] = $token;
            $this->WriteArchiveQueue($queue);
        });
        $this->SetTimerInterval(self::TIMER_ARCHIVE, 1000);
        $this->UpdateJobList();
        return $token;
    }

    // The period end may lie beyond the cutoff; weighting never extends past the cutoff.
    private function CreateArchivePeriod(int $start, int $end): array
    {
        return ['start' => $start, 'end' => $end,
            'min' => null, 'max' => null, 'sum' => 0.0, 'count' => 0, 'carry' => null,
            'weightedSum' => 0.0, 'weightedTime' => 0, 'delta' => 0.0, 'seeded' => false];
    }

    private function PublicJob(string $token, array $job): array
    {
        return ['token' => $token,
            'status' => $job['status'], 'variable' => $job['variable'],
            'processed' => $job['processed'], 'message' => $job['message']];
    }

    private function ProcessArchivePage(array &$job): bool
    {
        $rows = AC_GetLoggedValues($job['archive'], $job['variable'], $job['start'], $job['cursor'], self::ARCHIVE_PAGE_SIZE);
        if (!is_array($rows)) {
            throw new RuntimeException('Archive returned no valid data.');
        }
        $full = count($rows) >= self::ARCHIVE_PAGE_SIZE;
        $oldest = count($rows) ? min(array_column($rows, 'TimeStamp')) : 0;
        if ($full && $oldest >= $job['cursor']) {
            throw new RuntimeException('More than 5000 archive entries share the paging boundary timestamp.');
        }
        // A full page retains its oldest second for the following request.
        $usable = $full ? array_values(array_filter($rows, static fn($row) => (int) $row['TimeStamp'] > $oldest)) : $rows;
        usort($usable, static fn($a, $b) => (int) $b['TimeStamp'] <=> (int) $a['TimeStamp']);
        foreach ($usable as $row) {
            $t = (int) $row['TimeStamp'];
            if ($t > $job['cutoff']) {
                continue;
            }
            $value = (float) $row['Value'];
            // Only the boundary second can overlap with a live update.
            if ($t === $job['cutoff']) {
                $signature = $t . ':' . sprintf('%.15g', $value);
                $job['archiveSeen'][$signature] = ($job['archiveSeen'][$signature] ?? 0) + 1;
            }
            $newer = $job['next'];
            foreach ($job['periods'] as &$period) {
                // The next newer value defines the right edge of this sample's validity.
                $right = min($period['end'], $newer['timestamp'] ?? $job['cutoff']);
                $left = max($period['start'], $t);
                if ($right > $left) {
                    $period['weightedSum'] += $value * ($right - $left);
                    $period['weightedTime'] += $right - $left;
                }
                // The step to the next newer value belongs to the period of that value.
                // A drop is a counter reset: the new value counts from zero.
                if ($newer !== null && $newer['timestamp'] >= $period['start'] && $newer['timestamp'] < $period['end']) {
                    $period['delta'] += $newer['value'] >= $value ? $newer['value'] - $value : $newer['value'];
                }
                if ($period['start'] !== 0 && $t <= $period['start'] && !$period['seeded']) {
                    // The newest value before the period start is valid at its beginning.
                    $this->AccumulateArchiveExtreme($period, $value, $period['start']);
                    $period['carry'] = $value;
                    $period['seeded'] = true;
                }
                if ($t >= $period['start'] && $t < $period['end']) {
                    $this->AccumulateArchiveExtreme($period, $value, $t);
                    $period['sum'] += $value;
                    ++$period['count'];
                }
            }
            unset($period);
            if ($job['newest'] === null) {
                $job['newest'] = ['timestamp' => $t, 'value' => $value];
            }
            $job['next'] = ['timestamp' => $t, 'value' => $value];
            ++$job['processed'];
        }
        if ($full) {
            $job['cursor'] = $oldest;
            return false;
        }
        // A rebuild must not erase valid statistics when no archive samples exist.
        if ($job['processed'] === 0) {
            throw new RuntimeException('Archive contains no samples; rebuild was not committed.');
        }
        // Nothing is known before the oldest archived sample: a period that contains it is
        // weighted from that sample on, a period that ended before it gets no average.
        return true;
    }

    private function AccumulateArchiveExtreme(array &$period, float $value, int $timestamp): void
    {
        foreach (['min', 'max'] as $extreme) {
            $candidate = ['value' => $value, 'timestamp' => $timestamp];
            if ($this->IsBetterExtreme($extreme, $candidate, $period[$extreme])) {
                $period[$extreme] = $candidate;
            }
        }
    }

    // Ties keep the earliest occurrence.
    private function IsBetterExtreme(string $extreme, array $candidate, ?array $old): bool
    {
        if ($old === null) {
            return true;
        }
        if ($candidate['value'] == $old['value']) {
            return $candidate['timestamp'] < $old['timestamp'];
        }
        return $extreme === 'min' ? $candidate['value'] < $old['value'] : $candidate['value'] > $old['value'];
    }

    private function CommitArchiveJob(array $job): void
    {
        $variableID = (int) $job['variable'];
        $key = (string) $variableID;
        $cutoff = (int) $job['cutoff'];
        $definitions = $this->GetPeriodDefinitions();
        $state = $this->ReadState();
        $variableState = $state[$key] ?? ['periods' => []];
        $newestValue = isset($job['newest']) ? (float) $job['newest']['value'] : (float) GetValue($variableID);

        foreach ($job['periods'] as $periodKey => $archive) {
            $entry = $variableState['periods'][$periodKey] ?? null;
            if ($entry === null) {
                if ($archive['min'] === null) {
                    continue;
                }
                // Completed, non-repeating custom periods have no live initialization.
                $entry = ['periodStart' => $archive['start'], 'periodEnd' => $archive['end'], 'min' => null, 'max' => null]
                    + $this->CreateEmptyAggregates();
            }
            if ((int) $entry['periodStart'] !== (int) $archive['start']) {
                continue; // Do not overwrite a period that rolled over during import.
            }

            // Atomic replacement at commit: deleted archive peaks must not survive.
            $entry['min'] = $archive['min'];
            $entry['max'] = $archive['max'];

            // Without archive data, a running period starts with the newest value and a completed one stays empty.
            if ($archive['count'] > 0 || $archive['carry'] !== null) {
                $entry['arithmetic'] = ['sum' => (float) $archive['sum'], 'count' => (int) $archive['count'], 'carry' => $archive['carry']];
                $entry['timeweighted'] = $archive['weightedTime'] > 0
                    ? ['weightedSum' => (float) $archive['weightedSum'], 'weightedTime' => (int) $archive['weightedTime'],
                        'lastValue' => $newestValue, 'lastTimestamp' => $cutoff]
                    : $this->CreateAggregates($newestValue, $cutoff)['timeweighted'];
                $entry['delta'] = (float) $archive['delta'];
            } else {
                $entry = array_merge($entry, (int) $archive['end'] > $cutoff
                    ? $this->CreateAggregates($newestValue, $cutoff)
                    : $this->CreateEmptyAggregates());
            }
            $variableState['periods'][$periodKey] = $entry;
        }

        // The newest archived value is valid at the cutoff.
        if (isset($job['newest'])) {
            $this->RememberLastValue($variableState, $variableID, (float) $job['newest']['value'], (int) $job['newest']['timestamp']);
        }

        // Replay live changes after the archive snapshot, without counting values twice.
        $seen = $job['archiveSeen'] ?? [];
        $live = $job['live'] ?? [];
        usort($live, static fn($a, $b) => (int) $a['timestamp'] <=> (int) $b['timestamp']);
        foreach ($live as $sample) {
            $value = (float) $sample['value'];
            $timestamp = (int) $sample['timestamp'];
            $signature = $timestamp . ':' . sprintf('%.15g', $value);
            if (($seen[$signature] ?? 0) > 0) {
                --$seen[$signature];
                continue;
            }
            // Live updates were withheld during the rebuild and are applied now, including rollovers.
            $this->ApplyValue($variableState, $variableID, $definitions, $value, $timestamp, true);
            $this->RememberLastValue($variableState, $variableID, $value, $timestamp);
        }

        // Periods that rolled over while the rebuild withheld live updates start now.
        if (IPS_VariableExists($variableID)) {
            $currentValue = GetValue($variableID);
            if (is_int($currentValue) || is_float($currentValue)) {
                $this->EnsureCurrentPeriods($variableState, $variableID, $definitions, (float) $currentValue, time());
            }
        }

        $state[$key] = $variableState;
        $this->WriteState($state);
        $this->PublishVariable($variableID, $variableState);
    }

    // Returns true if a pending rebuild withholds the live update.
    private function RecordRebuildLiveValue(int $variableID, float $value, int $timestamp): bool
    {
        $jobs = $this->ReadArchiveJobs();
        $changed = false;
        $blocked = false;
        foreach ($jobs as &$job) {
            if ((int) $job['variable'] !== $variableID || !in_array($job['status'], ['queued', 'running'], true)) {
                continue;
            }
            $blocked = true;
            if ($timestamp >= $job['cutoff']) {
                $job['live'][] = ['timestamp' => $timestamp, 'value' => $value];
                $changed = true;
            }
        }
        unset($job);
        if ($changed) {
            $this->WriteArchiveJobs($jobs);
        }
        return $blocked;
    }

    private function GetRebuildingVariables(): array
    {
        $blocked = [];
        foreach ($this->ReadArchiveJobs() as $job) {
            if (in_array($job['status'], ['queued', 'running'], true)) {
                $blocked[(int) $job['variable']] = true;
            }
        }
        return $blocked;
    }

    private function ReplayFailedRebuild(array $job): void
    {
        $variableID = (int) $job['variable'];
        if (count($job['live']) === 0 || !IPS_VariableExists($variableID)) {
            return;
        }
        $live = $job['live'];
        usort($live, static fn($a, $b) => (int) $a['timestamp'] <=> (int) $b['timestamp']);

        $state = $this->ReadState();
        $key = (string) $variableID;
        $variableState = $state[$key] ?? ['periods' => []];
        $definitions = $this->GetPeriodDefinitions();
        foreach ($live as $sample) {
            $this->ApplyValue($variableState, $variableID, $definitions, (float) $sample['value'], (int) $sample['timestamp'], true);
            $this->RememberLastValue($variableState, $variableID, (float) $sample['value'], (int) $sample['timestamp']);
        }
        $state[$key] = $variableState;
        $this->WriteState($state);
        $this->PublishVariable($variableID, $variableState);
    }

    // Aborts all pending jobs, or only those of one variable.
    private function AbortArchiveJobs(string $reason, int $variableID = 0): void
    {
        $jobs = $this->ReadArchiveJobs();
        $aborted = [];
        foreach ($jobs as $token => &$job) {
            if (!in_array($job['status'], ['queued', 'running'], true)
                || ($variableID !== 0 && (int) $job['variable'] !== $variableID)) {
                continue;
            }
            $this->ReplayFailedRebuild($job);
            $job['status'] = 'failed';
            $job['message'] = $reason;
            $job['completed'] = time();
            $job['live'] = [];
            $aborted[(string) $token] = true;
        }
        unset($job);
        $this->WriteArchiveJobs($jobs);

        $queue = array_values(array_filter(
            $this->ReadArchiveQueue(),
            static fn($token) => !isset($aborted[(string) $token])
        ));
        $this->WriteArchiveQueue($queue);
        if (count($queue) === 0) {
            $this->SetTimerInterval(self::TIMER_ARCHIVE, 0);
        }
    }

    private function ReadArchiveJobs(): array
    {
        $value = json_decode($this->ReadAttributeString('ArchiveJobs'), true);
        return is_array($value) ? $value : [];
    }

    private function ReadArchiveQueue(): array
    {
        $value = json_decode($this->ReadAttributeString('ArchiveQueue'), true);
        return is_array($value) ? $value : [];
    }

    private function WriteArchiveJobs(array $jobs): void
    {
        $this->WriteAttributeString('ArchiveJobs', json_encode($jobs, JSON_PRESERVE_ZERO_FRACTION));
    }

    private function WriteArchiveQueue(array $queue): void
    {
        $this->WriteAttributeString('ArchiveQueue', json_encode(array_values($queue)));
    }

    // Serializes all access to State and ArchiveJobs; Symcon runs messages, timers and calls in parallel.
    private function WithLock(callable $callback)
    {
        if ($this->lockDepth === 0 && !IPS_SemaphoreEnter($this->GetLockName(), self::LOCK_TIMEOUT)) {
            throw new RuntimeException('Timeout while waiting for the instance lock.');
        }
        ++$this->lockDepth;
        try {
            return $callback();
        } finally {
            --$this->lockDepth;
            if ($this->lockDepth === 0) {
                IPS_SemaphoreLeave($this->GetLockName());
            }
        }
    }

    private function GetLockName(): string
    {
        return 'PERST_' . $this->InstanceID;
    }

    // Returns null for a valid configuration. A missing variable is not fatal: the others keep running.
    private function ValidateConfiguration(): ?array
    {
        if ($this->ReadPropertyInteger('RefreshInterval') < 0) {
            return $this->CreateError('The refresh interval is invalid.', true);
        }

        $variables = $this->GetConfiguredVariables();
        if (count($variables) === 0) {
            return $this->CreateError('At least one variable must be configured.', true);
        }

        $missing = false;
        $seenVariables = [];
        foreach ($variables as $row) {
            $variableID = (int) ($row['Variable'] ?? 0);
            if ($variableID > 0 && isset($seenVariables[$variableID])) {
                return $this->CreateError('A variable is configured more than once.', true);
            }
            $seenVariables[$variableID] = true;

            if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
                $missing = true;
                continue;
            }

            $variable = IPS_GetVariable($variableID);
            if (!in_array($variable['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
                return $this->CreateError('Only integer and float variables can be used.', true);
            }
        }

        $seenNames = [strtolower(self::ALLTIME) => true];
        foreach ($this->GetCustomPeriodDefinitions() as $period) {
            $name = $period['name'];
            if ($name === '') {
                return $this->CreateError('A time window has no name.', true);
            }

            $lowerName = strtolower($name);
            if (isset($seenNames[$lowerName])) {
                return $this->CreateError('Time window names must be unique and must not be "Alltime".', true);
            }
            $seenNames[$lowerName] = true;

            if (!$this->IsValidPeriodDefinition($period)) {
                return $this->CreateError('Invalid definition for time window "%s".', true, [$name]);
            }
        }

        return $missing ? $this->CreateError('A configured variable does not exist.', false) : null;
    }

    // Messages are stored untranslated and translated when the form is shown.
    private function CreateError(string $message, bool $fatal, array $args = []): array
    {
        return ['message' => $message, 'args' => $args, 'fatal' => $fatal];
    }

    private function ReadConfigurationError(): string
    {
        $error = json_decode($this->ReadAttributeString('ConfigurationError'), true);
        if (!is_array($error) || !isset($error['message'])) {
            return '';
        }
        return vsprintf($this->Translate($error['message']), $error['args'] ?? []);
    }

    private function GetConfiguredVariables(): array
    {
        $variables = json_decode($this->ReadPropertyString('Variables'), true);
        return is_array($variables) ? $variables : [];
    }

    private function GetConfiguredVariableIDs(): array
    {
        $result = [];
        foreach ($this->GetConfiguredVariables() as $row) {
            $variableID = (int) ($row['Variable'] ?? 0);
            if ($variableID > 0) {
                $result[] = $variableID;
            }
        }
        return $result;
    }

    // Configured variables that exist and can be processed.
    private function GetActiveVariableIDs(): array
    {
        $result = [];
        foreach ($this->GetConfiguredVariableIDs() as $variableID) {
            if (IPS_VariableExists($variableID)
                && in_array(IPS_GetVariable($variableID)['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
                $result[] = $variableID;
            }
        }
        return $result;
    }

    // The configured time windows followed by Alltime, which is always recorded.
    private function GetPeriodDefinitions(): array
    {
        $periods = $this->GetCustomPeriodDefinitions();
        $periods[] = [
            'key' => self::ALLTIME,
            'name' => self::ALLTIME,
            'kind' => 'alltime',
            'type' => 'alltime',
            'repeat' => false,
            'individual' => $this->ReadPropertyBoolean('IndividualAlltime')
        ];
        return $periods;
    }

    private function GetCustomPeriodDefinitions(): array
    {
        $rows = json_decode($this->ReadPropertyString('TimeWindows'), true);
        if (!is_array($rows)) {
            return [];
        }

        $periods = [];
        foreach ($rows as $row) {
            $date = $row['Start'] ?? [];
            if (is_string($date)) {
                $decoded = json_decode($date, true);
                $date = is_array($decoded) ? $decoded : [];
            }

            // Statistics are keyed by the stable window ID, so renaming keeps them.
            // A new row uses its name until EnsureTimeWindowIDs assigns an ID.
            $name = trim((string) ($row['Name'] ?? ''));
            $id = (string) ($row['ID'] ?? '');
            $periods[] = [
                'key'        => preg_match(self::TIME_WINDOW_ID_PATTERN, $id) === 1 ? $id : $name,
                'name'       => $name,
                'kind'       => 'custom',
                'type'       => strtolower((string) ($row['Unit'] ?? '')),
                'duration'   => (int) ($row['Duration'] ?? 1),
                'repeat'     => (bool) ($row['Repeat'] ?? true),
                'individual' => (bool) ($row['Individual'] ?? false),
                'startDay'   => (int) ($date['day'] ?? 0),
                'startMonth' => (int) ($date['month'] ?? 0),
                'startYear'  => (int) ($date['year'] ?? 0)
            ];
        }

        return $periods;
    }

    // The calendar periods as ordinary time windows. 1970 starts a decade; 1970-01-05 was a Monday.
    private function CreateDefaultTimeWindows(): array
    {
        $window = function (string $name, int $day, int $duration, string $unit): array {
            return [
                'Name' => $this->Translate($name),
                'Start' => json_encode(['year' => 1970, 'month' => 1, 'day' => $day]),
                'Duration' => $duration,
                'Unit' => $unit,
                'Repeat' => true,
                'Individual' => false
            ];
        };
        return [
            $window('Day', 1, 1, 'day'),
            $window('Week', 5, 1, 'week'),
            $window('Month', 1, 1, 'month'),
            $window('Year', 1, 1, 'year'),
            $window('Decade', 1, 10, 'year')
        ];
    }

    // Gives every time window a unique, immutable ID. Returns true if the configuration was rewritten.
    // A row that lost its ID gets back the previous ID of the window with the same name, so a
    // dropped ID column cannot discard statistics and individual variables.
    private function EnsureTimeWindowIDs(): bool
    {
        $rows = json_decode($this->ReadPropertyString('TimeWindows'), true);
        if (!is_array($rows)) {
            return false;
        }

        $previous = json_decode($this->ReadAttributeString('TimeWindowIDs'), true);
        $previous = is_array($previous) ? $previous : [];
        $used = [];
        foreach ($rows as $row) {
            $id = (string) ($row['ID'] ?? '');
            if (preg_match(self::TIME_WINDOW_ID_PATTERN, $id) === 1) {
                $used[$id] = ($used[$id] ?? 0) + 1;
            }
        }

        $seen = [];
        $changed = false;
        foreach ($rows as &$row) {
            $id = (string) ($row['ID'] ?? '');
            if (preg_match(self::TIME_WINDOW_ID_PATTERN, $id) !== 1 || isset($seen[$id])) {
                $id = '';
                foreach ($previous as $known) {
                    if ($known['name'] === trim((string) ($row['Name'] ?? '')) && !isset($used[$known['id']]) && !isset($seen[$known['id']])) {
                        $id = $known['id'];
                        break;
                    }
                }
                $row['ID'] = $id !== '' ? $id : 'tw_' . bin2hex(random_bytes(6));
                $id = $row['ID'];
                $changed = true;
            }
            $seen[$id] = true;
        }
        unset($row);

        $this->WriteAttributeString('TimeWindowIDs', json_encode(array_map(static fn($row) => [
            'id' => $row['ID'], 'name' => trim((string) ($row['Name'] ?? ''))
        ], $rows), JSON_UNESCAPED_UNICODE));
        if (!$changed) {
            return false;
        }

        $this->SendDebug('TimeWindows', 'Assigned missing time window IDs.', 0);
        IPS_SetProperty($this->InstanceID, 'TimeWindows', json_encode($rows));
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    private function IsValidPeriodDefinition(array $period): bool
    {
        if (!in_array($period['type'], ['day', 'week', 'month', 'year'], true)) {
            return false;
        }
        if ($period['duration'] < 1) {
            return false;
        }
        return checkdate($period['startMonth'], $period['startDay'], $period['startYear']);
    }

    private function GetPeriodDefinition(string $name): ?array
    {
        foreach ($this->GetPeriodDefinitions() as $period) {
            if (strcasecmp($period['name'], $name) === 0) {
                return $period;
            }
        }
        return null;
    }

    private function SynchronizeOutputVariables(): void
    {
        $wanted = [];
        $state = $this->ReadState();
        foreach ($this->GetConfiguredVariableIDs() as $variableID) {
            $wanted[$variableID] = true;
            // A deleted source keeps its output until its row is removed.
            if (!IPS_VariableExists($variableID)) {
                continue;
            }

            $ident = $this->GetOutputIdent($variableID);
            $this->RegisterVariableString($ident, IPS_GetName($variableID) . ' - PeriodicStatistics');
        }

        $individual = $this->SynchronizeIndividualVariables();

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $ident = IPS_GetObject($childID)['ObjectIdent'];
            if (ctype_digit($ident) && !isset($wanted[(int) $ident])) {
                $this->UnregisterVariable($ident);
            } elseif (preg_match(self::INDIVIDUAL_IDENT_PATTERN, $ident, $match) === 1 && !isset($individual[$ident])
                && (!isset($wanted[(int) $match[1]]) || IPS_VariableExists((int) $match[1]))) {
                // Individual variables of a deleted source stay, like its JSON output.
                $this->UnregisterVariable($ident);
            }
        }

        $this->PruneState(array_keys($wanted), array_column($this->GetPeriodDefinitions(), 'key'));

        $state = $this->ReadState();
        foreach (array_keys($wanted) as $variableID) {
            if (IPS_VariableExists($variableID) && isset($state[(string) $variableID])) {
                $this->PublishVariable($variableID, $state[(string) $variableID]);
            }
        }
    }

    // Creates the selected individual variables and returns them keyed by ident.
    private function SynchronizeIndividualVariables(): array
    {
        $generatedNames = json_decode($this->ReadAttributeString('GeneratedNames'), true);
        $generatedNames = is_array($generatedNames) ? $generatedNames : [];
        $targets = $this->GetIndividualTargets();
        foreach ($targets as $ident => $target) {
            $name = IPS_GetName($target['variable']) . ' - ' . $target['periodCaption'] . ' ' . $this->Translate(self::INDIVIDUAL_METRICS[$target['metric']][1]);
            $objectID = @$this->GetIDForIdent($ident);
            if ($objectID !== false && IPS_GetVariable($objectID)['VariableType'] !== $target['type']) {
                // The source type changed; recreate with the matching type.
                $this->UnregisterVariable($ident);
                $objectID = false;
            }
            if ($objectID === false) {
                if ($target['type'] === VARIABLETYPE_FLOAT) {
                    $this->RegisterVariableFloat($ident, $name, '', $target['position']);
                } else {
                    $this->RegisterVariableInteger($ident, $name, '', $target['position']);
                }
                $this->ApplyIndividualPresentation($target, $this->GetIDForIdent($ident));
                $generatedNames[$ident] = $name;
            } elseif (($generatedNames[$ident] ?? IPS_GetName($objectID)) === IPS_GetName($objectID)) {
                // Follow renamed sources and periods, unless the user renamed the variable.
                if (IPS_GetName($objectID) !== $name) {
                    IPS_SetName($objectID, $name);
                }
                $generatedNames[$ident] = $name;
            }
        }
        $this->WriteAttributeString('GeneratedNames', json_encode(array_intersect_key($generatedNames, $targets), JSON_UNESCAPED_UNICODE));
        return $targets;
    }

    // Returns the individual variables the configuration asks for, keyed by ident.
    private function GetIndividualTargets(int $onlyVariableID = 0): array
    {
        $definitions = [];
        foreach ($this->GetPeriodDefinitions() as $index => $period) {
            if ($period['individual']) {
                $definitions[] = $period + ['index' => $index];
            }
        }

        $targets = [];
        foreach ($this->GetConfiguredVariables() as $variableIndex => $row) {
            $variableID = (int) ($row['Variable'] ?? 0);
            if (($onlyVariableID !== 0 && $variableID !== $onlyVariableID) || $variableID <= 0 || !IPS_VariableExists($variableID)) {
                continue;
            }
            $sourceType = IPS_GetVariable($variableID)['VariableType'];
            if (!in_array($sourceType, [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
                continue;
            }
            $types = [
                'min' => $sourceType, 'max' => $sourceType, 'avga' => VARIABLETYPE_FLOAT, 'avgt' => VARIABLETYPE_FLOAT,
                'delta' => $sourceType, 'integral' => VARIABLETYPE_FLOAT,
                'mintime' => VARIABLETYPE_INTEGER, 'maxtime' => VARIABLETYPE_INTEGER
            ];
            $metrics = [];
            foreach (self::INDIVIDUAL_METRICS as $metric => [$column]) {
                if ($row[$column] ?? false) {
                    $metrics[$metric] = $types[$metric];
                }
            }
            // Timestamps only accompany a selected extreme.
            if (!isset($metrics['min'])) {
                unset($metrics['mintime']);
            }
            if (!isset($metrics['max'])) {
                unset($metrics['maxtime']);
            }
            foreach ($definitions as $period) {
                $metricIndex = 0;
                foreach ($metrics as $metric => $type) {
                    $ident = $variableID . '_' . $period['key'] . '_' . $metric;
                    $targets[$ident] = [
                        'variable' => $variableID,
                        'periodKey' => $period['key'],
                        'periodCaption' => $period['kind'] === 'custom' ? $period['name'] : $this->Translate($period['name']),
                        'metric' => $metric,
                        'type' => $type,
                        'position' => ($variableIndex + 1) * 1000 + $period['index'] * 10 + $metricIndex++
                    ];
                }
            }
        }
        return $targets;
    }

    // Copies the source presentation to a new individual variable; timestamps show date and time.
    // The integral has a different unit (value × hours) and stays without presentation.
    private function ApplyIndividualPresentation(array $target, int $objectID): void
    {
        if (in_array($target['metric'], ['mintime', 'maxtime'], true)) {
            if (IPS_VariableProfileExists('~UnixTimestamp')) {
                IPS_SetVariableCustomProfile($objectID, '~UnixTimestamp');
            }
            return;
        }
        if ($target['metric'] === 'integral') {
            return;
        }

        $source = IPS_GetVariable($target['variable']);
        foreach (['VariableCustomPresentation', 'VariablePresentation'] as $field) {
            $presentation = $source[$field] ?? [];
            if (is_string($presentation)) {
                $presentation = json_decode($presentation, true);
            }
            if (is_array($presentation) && count($presentation) > 0) {
                IPS_SetVariableCustomPresentation($objectID, $presentation);
                return;
            }
        }
        foreach (['VariableCustomProfile', 'VariableProfile'] as $field) {
            $profile = (string) ($source[$field] ?? '');
            // A profile only fits a variable of its own type, e.g. not an integer profile on a float average.
            if ($profile !== '' && IPS_VariableProfileExists($profile)
                && IPS_GetVariableProfile($profile)['ProfileType'] === $target['type']) {
                IPS_SetVariableCustomProfile($objectID, $profile);
                return;
            }
        }
    }

    private function RegisterVariableWatches(array $variableIDs): void
    {
        foreach ($variableIDs as $variableID) {
            $this->RegisterMessage($variableID, VM_UPDATE);
            $this->RegisterMessage($variableID, VM_DELETE);
            $this->RegisterReference($variableID);
        }
        $this->WriteAttributeString('RegisteredVariables', json_encode(array_values($variableIDs)));
    }

    private function UnregisterVariableWatches(): void
    {
        $registered = json_decode($this->ReadAttributeString('RegisteredVariables'), true);
        if (!is_array($registered)) {
            return;
        }

        foreach ($registered as $variableID) {
            $variableID = (int) $variableID;
            if ($variableID <= 0) {
                continue;
            }
            @$this->UnregisterMessage($variableID, VM_UPDATE);
            @$this->UnregisterMessage($variableID, VM_DELETE);
            @$this->UnregisterReference($variableID);
        }
        $this->WriteAttributeString('RegisteredVariables', '[]');
    }

    private function UpdateRefreshTimer(array $variableIDs): void
    {
        $minutes = $this->ReadPropertyInteger('RefreshInterval');
        $this->SetTimerInterval(self::TIMER_REFRESH, count($variableIDs) > 0 && $minutes > 0 ? $minutes * 60000 : 0);
    }

    // Starts periods that rolled over. Must run under the lock.
    private function SynchronizeCurrentValues(array $variableIDs): void
    {
        $now = time();
        $blocked = $this->GetRebuildingVariables();
        $definitions = $this->GetPeriodDefinitions();
        $state = $this->ReadState();
        $changed = [];
        $remembered = false;
        foreach ($variableIDs as $variableID) {
            if (isset($blocked[$variableID])) {
                continue;
            }
            $value = GetValue($variableID);
            if (!is_int($value) && !is_float($value)) {
                continue;
            }

            $key = (string) $variableID;
            $variableState = $state[$key] ?? ['periods' => []];
            // Without history, the current value is known to be valid since its last change.
            if (!isset($variableState['last'])) {
                $this->RememberLastValue($variableState, $variableID, (float) $value, min($now, (int) IPS_GetVariable($variableID)['VariableChanged']));
                $state[$key] = $variableState;
                $remembered = true;
            }
            if ($this->EnsureCurrentPeriods($variableState, $variableID, $definitions, (float) $value, $now)) {
                $state[$key] = $variableState;
                $changed[] = $variableID;
            }
        }

        if ($remembered || count($changed) > 0) {
            $this->WriteState($state);
            foreach ($changed as $variableID) {
                $this->PublishVariable($variableID, $state[(string) $variableID]);
            }
        }
    }

    private function EnsureCurrentPeriods(array &$variableState, int $variableID, array $definitions, float $currentValue, int $now): bool
    {
        $currentValue = $this->NormalizeValue($variableID, $currentValue);
        $changed = false;
        foreach ($definitions as $period) {
            $bounds = $this->GetPeriodBounds($period, $now);
            if ($bounds === null) {
                continue;
            }
            $periodKey = $period['key'];
            $entry = $variableState['periods'][$periodKey] ?? null;
            if (!is_array($entry) || (int) ($entry['periodStart'] ?? -1) !== $bounds[0]) {
                $variableState['periods'][$periodKey] = $period['type'] === 'alltime'
                    ? $this->CreatePeriodEntry($bounds, $currentValue, $now)
                    : $this->CreateCarriedPeriodEntry($bounds, $variableState, $currentValue, $now);
                $changed = true;
            }
        }
        return $changed;
    }

    // Applies one sample to the given periods in memory. Returns true if published data changed.
    private function ApplyValue(array &$variableState, int $variableID, array $definitions, float $value, int $timestamp, bool $allowRollover): bool
    {
        $value = $this->NormalizeValue($variableID, $value);
        $changed = false;
        foreach ($definitions as $period) {
            $bounds = $this->GetPeriodBounds($period, $timestamp);
            if ($bounds === null) {
                continue;
            }
            $periodKey = $period['key'];
            $entry = $variableState['periods'][$periodKey] ?? null;
            $entryStart = is_array($entry) ? (int) ($entry['periodStart'] ?? -1) : -1;
            if ($entryStart !== $bounds[0]) {
                // Never fall back to an older period.
                if (!$allowRollover || $entryStart > $bounds[0]) {
                    continue;
                }
                $variableState['periods'][$periodKey] = $period['type'] === 'alltime'
                    ? $this->CreatePeriodEntry($bounds, $value, $timestamp)
                    : $this->CreateCarriedPeriodEntry($bounds, $variableState, $value, $timestamp);
                $changed = true;
            }
            if ($this->UpdatePeriodEntry($variableState['periods'][$periodKey], $value, $timestamp)) {
                $changed = true;
            }
        }
        return $changed;
    }

    private function RememberLastValue(array &$variableState, int $variableID, float $value, int $timestamp): void
    {
        if ($timestamp >= (int) ($variableState['last']['timestamp'] ?? 0)) {
            $variableState['last'] = ['value' => $this->NormalizeValue($variableID, $value), 'timestamp' => $timestamp];
        }
    }

    // The last observed value is valid from the period start, or from its own timestamp if it
    // arrived later. Without history, nothing is known before the given value and timestamp.
    private function CreateCarriedPeriodEntry(array $bounds, array $variableState, $value, int $timestamp): array
    {
        if (!isset($variableState['last'])) {
            return $this->CreatePeriodEntry($bounds, $value, $timestamp);
        }
        $last = $variableState['last'];
        return $this->CreatePeriodEntry($bounds, $last['value'], max($bounds[0], min((int) $last['timestamp'], $timestamp)));
    }

    // A new period starts with the value that is valid at its beginning.
    // Its timestamp is the period boundary, even if the rollover is noticed later.
    private function CreatePeriodEntry(array $bounds, $value, int $timestamp): array
    {
        return [
            'periodStart' => $bounds[0],
            'periodEnd' => $bounds[1],
            'min' => ['value' => $value, 'timestamp' => $timestamp],
            'max' => ['value' => $value, 'timestamp' => $timestamp]
        ] + $this->CreateAggregates($value, $timestamp);
    }

    // State of the averages, the difference and the integral, starting with the value valid at $timestamp.
    // The arithmetic average covers the changes within the period; until the first one arrives,
    // the carried-in value stands in for it. The integral is derived from the time-weighted sum.
    private function CreateAggregates($value, int $timestamp): array
    {
        return [
            'arithmetic' => ['sum' => 0.0, 'count' => 0, 'carry' => (float) $value],
            'timeweighted' => ['weightedSum' => 0.0, 'weightedTime' => 0, 'lastValue' => (float) $value, 'lastTimestamp' => $timestamp],
            'delta' => 0.0
        ];
    }

    private function CreateEmptyAggregates(): array
    {
        return ['arithmetic' => null, 'timeweighted' => null, 'delta' => null];
    }

    private function UpdatePeriodEntry(array &$entry, $value, int $timestamp): bool
    {
        $this->UpdateExtremes($entry, $value, $timestamp);
        if (!is_array($entry['arithmetic'] ?? null) || !is_array($entry['timeweighted'] ?? null)) {
            $entry = array_merge($entry, $this->CreateAggregates($value, $timestamp));
        }

        $entry['arithmetic']['sum'] = (float) $entry['arithmetic']['sum'] + (float) $value;
        $entry['arithmetic']['count'] = (int) $entry['arithmetic']['count'] + 1;

        // Out-of-order samples cannot be weighted and are ignored for the time-based values.
        $timeweighted = &$entry['timeweighted'];
        if ($timestamp >= (int) $timeweighted['lastTimestamp']) {
            $previous = (float) $timeweighted['lastValue'];
            $duration = $timestamp - (int) $timeweighted['lastTimestamp'];
            $timeweighted['weightedSum'] = (float) $timeweighted['weightedSum'] + $previous * $duration;
            $timeweighted['weightedTime'] = (int) $timeweighted['weightedTime'] + $duration;
            // A drop is a counter reset: the new value counts from zero.
            $entry['delta'] = (float) ($entry['delta'] ?? 0.0) + ((float) $value >= $previous ? (float) $value - $previous : (float) $value);
            $timeweighted['lastValue'] = (float) $value;
            $timeweighted['lastTimestamp'] = $timestamp;
        }
        unset($timeweighted);
        return true;
    }

    private function UpdateExtremes(array &$entry, $value, int $timestamp): bool
    {
        $changed = false;
        if (($entry['min'] ?? null) === null || $value < $entry['min']['value']) {
            $entry['min'] = ['value' => $value, 'timestamp' => $timestamp];
            $changed = true;
        }
        if (($entry['max'] ?? null) === null || $value > $entry['max']['value']) {
            $entry['max'] = ['value' => $value, 'timestamp' => $timestamp];
            $changed = true;
        }
        return $changed;
    }

    private function GetPeriodBounds(array $period, int $timestamp): ?array
    {
        if ($period['kind'] === 'alltime') {
            // Zero is a stable identity for the lifetime period, not a measured timestamp.
            return [0, 2147483647];
        }
        return $this->GetCustomPeriodBounds($period, $timestamp);
    }

    private function GetCustomAnchor(array $period): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))
            ->setDate($period['startYear'], $period['startMonth'], $period['startDay'])
            ->setTime(0, 0, 0);
    }

    private function GetCustomPeriodBounds(array $period, int $timestamp): ?array
    {
        $anchor = $this->GetCustomAnchor($period);
        $anchorTimestamp = $anchor->getTimestamp();

        if ($timestamp < $anchorTimestamp) {
            return null;
        }

        if (!$period['repeat']) {
            $end = $this->ShiftCustomBoundary($anchor, $period['type'], $period['duration']);
            if ($timestamp >= $end->getTimestamp()) {
                return null;
            }
            return [$anchorTimestamp, $end->getTimestamp()];
        }

        switch ($period['type']) {
            case 'day':
            case 'week':
                $daysPerPeriod = $period['duration'] * ($period['type'] === 'week' ? 7 : 1);
                $moment = (new DateTimeImmutable('@' . $timestamp))->setTimezone($anchor->getTimezone())->setTime(0, 0, 0);
                $daysSinceAnchor = (int) $anchor->diff($moment)->format('%a');
                $index = intdiv($daysSinceAnchor, $daysPerPeriod);
                $start = $anchor->modify('+' . ($index * $daysPerPeriod) . ' days');
                $end = $start->modify('+' . $daysPerPeriod . ' days');
                break;

            case 'month':
                [$start, $end] = $this->GetRecurringMonthBounds($anchor, $period['duration'], $timestamp);
                break;

            case 'year':
                [$start, $end] = $this->GetRecurringYearBounds($anchor, $period['duration'], $timestamp);
                break;

            default:
                throw new RuntimeException('Unsupported custom period type.');
        }

        return [$start->getTimestamp(), $end->getTimestamp()];
    }

    private function GetRecurringMonthBounds(DateTimeImmutable $anchor, int $duration, int $timestamp): array
    {
        $timezone = $anchor->getTimezone();
        $moment = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
        $anchorMonthIndex = ((int) $anchor->format('Y')) * 12 + ((int) $anchor->format('n')) - 1;
        $momentMonthIndex = ((int) $moment->format('Y')) * 12 + ((int) $moment->format('n')) - 1;
        $index = max(0, intdiv($momentMonthIndex - $anchorMonthIndex, $duration));

        $start = $this->ShiftCustomBoundary($anchor, 'month', $index * $duration);
        while ($start->getTimestamp() > $timestamp && $index > 0) {
            --$index;
            $start = $this->ShiftCustomBoundary($anchor, 'month', $index * $duration);
        }
        $end = $this->ShiftCustomBoundary($anchor, 'month', ($index + 1) * $duration);

        while ($timestamp >= $end->getTimestamp()) {
            ++$index;
            $start = $end;
            $end = $this->ShiftCustomBoundary($anchor, 'month', ($index + 1) * $duration);
        }

        return [$start, $end];
    }

    private function GetRecurringYearBounds(DateTimeImmutable $anchor, int $duration, int $timestamp): array
    {
        $timezone = $anchor->getTimezone();
        $moment = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
        $index = max(0, intdiv((int) $moment->format('Y') - (int) $anchor->format('Y'), $duration));

        $start = $this->ShiftCustomBoundary($anchor, 'year', $index * $duration);
        while ($start->getTimestamp() > $timestamp && $index > 0) {
            --$index;
            $start = $this->ShiftCustomBoundary($anchor, 'year', $index * $duration);
        }
        $end = $this->ShiftCustomBoundary($anchor, 'year', ($index + 1) * $duration);

        while ($timestamp >= $end->getTimestamp()) {
            ++$index;
            $start = $end;
            $end = $this->ShiftCustomBoundary($anchor, 'year', ($index + 1) * $duration);
        }

        return [$start, $end];
    }

    private function ShiftCustomBoundary(DateTimeImmutable $anchor, string $unit, int $amount): DateTimeImmutable
    {
        if ($amount === 0) {
            return $anchor;
        }

        switch ($unit) {
            case 'day':
                return $anchor->modify('+' . $amount . ' days');

            case 'week':
                return $anchor->modify('+' . ($amount * 7) . ' days');

            case 'month':
                $anchorYear = (int) $anchor->format('Y');
                $anchorMonth = (int) $anchor->format('n');
                $anchorDay = (int) $anchor->format('j');
                [$year, $month] = $this->ShiftMonth($anchorYear, $anchorMonth, $amount);
                return $this->CreateClampedDate($year, $month, $anchorDay, $anchor->getTimezone());

            case 'year':
                return $this->CreateClampedDate(
                    (int) $anchor->format('Y') + $amount,
                    (int) $anchor->format('n'),
                    (int) $anchor->format('j'),
                    $anchor->getTimezone()
                );

            default:
                throw new RuntimeException('Unsupported custom period unit.');
        }
    }

    private function CreateClampedDate(int $year, int $month, int $day, DateTimeZone $timezone): DateTimeImmutable
    {
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $day = min($day, $daysInMonth);
        return (new DateTimeImmutable('now', $timezone))->setDate($year, $month, $day)->setTime(0, 0, 0);
    }

    private function ShiftMonth(int $year, int $month, int $delta): array
    {
        $index = ($year * 12 + ($month - 1)) + $delta;
        $newYear = intdiv($index, 12);
        $newMonth = ($index % 12) + 1;
        return [$newYear, $newMonth];
    }

    private function PublishVariable(int $variableID, array $variableState): void
    {
        $ident = $this->GetOutputIdent($variableID);
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        $public = [
            'VariableID' => $variableID,
            'Data' => $this->CreatePublicData($variableState)
        ];
        $json = json_encode($public, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $this->SetValue($ident, $json);

        $this->PublishIndividualVariables($variableID, $variableState);
    }

    // Writes only changed values; empty statistics leave the variable untouched.
    private function PublishIndividualVariables(int $variableID, array $variableState): void
    {
        foreach ($this->GetIndividualTargets($variableID) as $ident => $target) {
            $entry = $variableState['periods'][$target['periodKey']] ?? null;
            $objectID = @$this->GetIDForIdent($ident);
            if ($entry === null || $objectID === false) {
                continue;
            }
            $values = $this->CalculateValues($entry);
            switch ($target['metric']) {
                case 'mintime':
                    $value = $entry['min']['timestamp'] ?? null;
                    break;
                case 'maxtime':
                    $value = $entry['max']['timestamp'] ?? null;
                    break;
                case 'min':
                case 'max':
                    $value = $entry[$target['metric']]['value'] ?? null;
                    break;
                default:
                    $value = $values[['avga' => 'avgArithmetic', 'avgt' => 'avgTimeWeighted', 'delta' => 'delta', 'integral' => 'integral'][$target['metric']]];
            }
            if ($value === null) {
                continue;
            }
            $value = $target['type'] === VARIABLETYPE_FLOAT ? (float) $value : (int) round((float) $value);
            if (GetValue($objectID) !== $value) {
                $this->SetValue($ident, $value);
            }
        }
    }

    private function CreatePublicData(array $variableState): array
    {
        $result = [];
        foreach ($this->GetPeriodDefinitions() as $period) {
            $entry = $variableState['periods'][$period['key']] ?? null;
            if ($entry === null) {
                continue;
            }
            $result[$period['name']] = [
                'min' => $entry['min'] ?? null,
                'max' => $entry['max'] ?? null
            ] + $this->CalculateValues($entry);
        }
        return $result;
    }

    // Averages, difference and integral (value × hours) of a period, up to now or its end.
    private function CalculateValues(array $entry): array
    {
        $result = ['avgArithmetic' => null, 'avgTimeWeighted' => null, 'delta' => null, 'integral' => null];

        $arithmetic = $entry['arithmetic'] ?? null;
        if (is_array($arithmetic)) {
            $count = (int) $arithmetic['count'];
            $result['avgArithmetic'] = $count > 0
                ? (float) $arithmetic['sum'] / $count
                : (isset($arithmetic['carry']) ? (float) $arithmetic['carry'] : null);
        }

        $timeweighted = $entry['timeweighted'] ?? null;
        if (is_array($timeweighted)) {
            $weightedSum = (float) $timeweighted['weightedSum'];
            $weightedTime = (int) $timeweighted['weightedTime'];
            $lastTimestamp = (int) $timeweighted['lastTimestamp'];
            $end = min(time(), (int) $entry['periodEnd']);
            if ($end > $lastTimestamp) {
                $weightedSum += (float) $timeweighted['lastValue'] * ($end - $lastTimestamp);
                $weightedTime += $end - $lastTimestamp;
            }
            $result['avgTimeWeighted'] = $weightedTime > 0 ? $weightedSum / $weightedTime : (float) $timeweighted['lastValue'];
            $result['integral'] = $weightedSum / 3600;
        }

        if (isset($entry['delta'])) {
            $result['delta'] = (float) $entry['delta'];
        }
        return $result;
    }

    private function ScheduleNextRollover(): void
    {
        $timezone = new DateTimeZone(date_default_timezone_get());
        $now = new DateTimeImmutable('now', $timezone);
        $next = $now->modify('tomorrow')->setTime(0, 0, 1);
        $milliseconds = max(1000, ($next->getTimestamp() - time()) * 1000);
        $this->SetTimerInterval(self::TIMER_ROLLOVER, $milliseconds);
    }

    private function NormalizeValue(int $variableID, float $value)
    {
        $variable = IPS_GetVariable($variableID);
        if ($variable['VariableType'] === VARIABLETYPE_INTEGER) {
            return (int) $value;
        }
        return $value;
    }

    private function EnsureValidSourceVariable(int $variableID): void
    {
        if (!in_array($variableID, $this->GetConfiguredVariableIDs(), true)) {
            throw new InvalidArgumentException('Variable is not configured in this instance.');
        }
    }

    private function GetOutputIdent(int $variableID): string
    {
        return (string) $variableID;
    }

    private function ReadState(): array
    {
        $state = json_decode($this->ReadAttributeString('State'), true);
        return is_array($state) ? $state : [];
    }

    private function WriteState(array $state): void
    {
        $this->WriteAttributeString(
            'State',
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
        );
    }

    // Removes statistics of variables and periods that are no longer configured.
    private function PruneState(array $variableIDs, array $periodKeys): void
    {
        $allowed = array_fill_keys(array_map('strval', $variableIDs), true);
        $allowedPeriods = array_fill_keys($periodKeys, true);
        $state = $this->ReadState();
        $changed = false;
        foreach (array_keys($state) as $key) {
            if (!isset($allowed[(string) $key])) {
                unset($state[$key]);
                $changed = true;
                continue;
            }
            foreach (array_keys($state[$key]['periods']) as $periodKey) {
                if (!isset($allowedPeriods[$periodKey])) {
                    unset($state[$key]['periods'][$periodKey]);
                    $changed = true;
                }
            }
        }
        if ($changed) {
            $this->WriteState($state);
        }
    }
}
