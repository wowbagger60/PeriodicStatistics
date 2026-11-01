<?php declare(strict_types=1);

class PeriodicStatistics extends IPSModuleStrict
{
    private const STATUS_INVALID_CONFIGURATION = 201;
    private const STATUS_MISSING_VARIABLE = 202;
    private const TIMER_ROLLOVER = 'Rollover';
    private const TIMER_MERGE = 'ArchiveMerge';
    private const TIMER_REFRESH = 'Refresh';
    private const ARCHIVE_PAGE_SIZE = 5000;
    private const LOCK_TIMEOUT = 30000;
    private const BUILTIN_PERIODS = ['Day', 'Week', 'Month', 'Year', 'Alltime'];
    private const ARCHIVE_PROGRESS_FIELDS = ['processed', 'cursor', 'periods', 'next', 'newest', 'archiveSeen'];
    private const TIME_WINDOW_ID_PATTERN = '/^tw_[0-9a-f]{12}$/';

    // Nesting depth of the instance lock within the current PHP call.
    private int $lockDepth = 0;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('FirstDayOfWeek', 1);
        $this->RegisterPropertyInteger('RefreshInterval', 15);
        $this->RegisterPropertyString('Variables', '[]');
        $this->RegisterPropertyString('TimeWindows', '[]');

        $this->RegisterTimer(self::TIMER_ROLLOVER, 0, 'PERST_InternalTimer($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_MERGE, 0, 'PERST_InternalMergeTimer($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_REFRESH, 0, 'PERST_InternalRefreshTimer($_IPS[\'TARGET\']);');

        $this->RegisterAttributeString('RegisteredVariables', '[]');
        $this->RegisterAttributeString('State', '{}');
        $this->RegisterAttributeString('ConfigurationError', '');
        $this->RegisterAttributeString('MergeJobs', '{}');
        $this->RegisterAttributeString('MergeQueue', '[]');
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
        $error = $this->ReadConfigurationError();

        if ($error !== '') {
            array_unshift($form['elements'], [
                'type'    => 'Label',
                'caption' => $error
            ]);
        }

        return json_encode($form);
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
                ? $this->CreatePublicData($state[(string) $VariableID], $VariableID)
                : [];
        }

        $result = [];
        foreach ($this->GetConfiguredVariableIDs() as $variableID) {
            if (isset($state[(string) $variableID])) {
                $result[$variableID] = $this->CreatePublicData($state[(string) $variableID], $variableID);
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
            $data = $this->CreatePublicData($state[(string) $variableID], $variableID);
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

    // Republishes time-weighted averages, which change with time even without new values.
    public function InternalRefreshTimer(): void
    {
        $this->WithLock(function (): void {
            $state = $this->ReadState();
            foreach ($this->GetActiveVariableIDs() as $variableID) {
                if ($this->GetAverageType($variableID) === 'timeweighted' && isset($state[(string) $variableID])) {
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

        if (($period['kind'] ?? '') !== 'custom' || ($period['repeat'] ?? true)) {
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
        if (!in_array($extreme, ['', 'min', 'max', 'avg'], true)) {
            throw new InvalidArgumentException('Extreme must be "min", "max", "avg" or empty.');
        }

        return $this->WithLock(function () use ($VariableID, $period, $extreme): bool {
            $state = $this->ReadState();
            $key = (string) $VariableID;
            $name = $period['key'];
            if (!isset($state[$key]['periods'][$name])) {
                return false;
            }

            $entry = &$state[$key]['periods'][$name];
            if ($extreme === '' || $extreme === 'min') {
                $entry['min'] = null;
            }
            if ($extreme === '' || $extreme === 'max') {
                $entry['max'] = null;
            }
            if ($extreme === '' || $extreme === 'avg') {
                // A running period restarts its average with the current value; a completed one has none left.
                $averageType = $this->GetAverageType($VariableID);
                $now = time();
                $entry['averageType'] = $averageType;
                $entry['average'] = (int) ($entry['periodEnd'] ?? 0) > $now
                    ? $this->CreateAverageState($averageType, $this->NormalizeValue($VariableID, (float) GetValue($VariableID)), $now)
                    : null;
            }
            unset($entry);

            $this->WriteState($state);
            $this->PublishVariable($VariableID, $state[$key]);
            return true;
        });
    }

    // Archive import is asynchronous. Jobs survive timer ticks and Symcon restarts,
    // but applying the configuration aborts them.
    public function MergeFromArchive(int $VariableID): string
    {
        return $this->StartArchiveJob($VariableID, false);
    }

    public function RebuildFromArchive(int $VariableID): string
    {
        return $this->StartArchiveJob($VariableID, true);
    }

    public function GetJobStatus(string $Token): array
    {
        $jobs = $this->ReadMergeJobs();
        if (!isset($jobs[$Token])) {
            return ['status' => 'unknown', 'variable' => 0, 'processed' => 0, 'message' => ''];
        }
        return $this->PublicJob($Token, $jobs[$Token]);
    }

    public function GetJobs(): array
    {
        $result = [];
        foreach ($this->ReadMergeJobs() as $token => $job) {
            $result[] = $this->PublicJob((string) $token, $job);
        }
        return $result;
    }

    public function InternalMergeTimer(): void
    {
        $work = $this->WithLock(function (): ?array {
            $jobs = $this->ReadMergeJobs();
            foreach ($jobs as $token => $job) {
                if (in_array($job['status'], ['finished', 'failed'], true) && time() - ($job['completed'] ?? time()) > 3600) {
                    unset($jobs[$token]);
                }
            }
            $queue = $this->ReadMergeQueue();
            while (count($queue) > 0 && !isset($jobs[$queue[0]])) {
                array_shift($queue);
            }
            $token = $queue[0] ?? null;
            if ($token !== null) {
                $jobs[$token]['status'] = 'running';
            }
            $this->WriteMergeJobs($jobs);
            $this->WriteMergeQueue($queue);
            if ($token === null) {
                $this->SetTimerInterval(self::TIMER_MERGE, 0);
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
            $jobs = $this->ReadMergeJobs();
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
                $this->SendDebug('ArchiveMerge', $error->getMessage(), 0);
            }
            if ($current['status'] !== 'running') {
                $current['completed'] = time();
                $current['live'] = [];
                $this->WriteMergeQueue(array_values(array_filter(
                    $this->ReadMergeQueue(),
                    static fn($queued) => $queued !== $token
                )));
            }
            $jobs[$token] = $current;
            $this->WriteMergeJobs($jobs);
            $this->SetTimerInterval(self::TIMER_MERGE, count($this->ReadMergeQueue()) > 0 ? 1000 : 0);
        });
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
            $this->SetTimerInterval(self::TIMER_MERGE, 0);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
            return;
        }

        $variableIDs = $this->GetActiveVariableIDs();
        $this->RegisterVariableWatches($variableIDs);
        $this->WithLock(function () use ($variableIDs): void {
            $this->SynchronizeOutputVariables();
            $this->SynchronizeCurrentValues($variableIDs);
        });
        $this->ScheduleNextRollover();
        $this->UpdateRefreshTimer($variableIDs);
        $this->SetTimerInterval(self::TIMER_MERGE, count($this->ReadMergeQueue()) > 0 ? 1000 : 0);

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
            if ($this->RecordMergeLiveValue($variableID, $value, $timestamp)) {
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

    // A deleted source only disables its own statistics; output and state stay until its row is removed.
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

    private function StartArchiveJob(int $VariableID, bool $rebuild): string
    {
        $this->EnsureValidSourceVariable($VariableID);
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
                $periods[$period['key']] = $this->CreateArchivePeriod($bounds[0], min($bounds[1], $now), $bounds[1]);
            } elseif (($period['kind'] ?? '') === 'custom' && !($period['repeat'] ?? true)) {
                // A completed fixed window is also eligible for historical reconstruction.
                $anchor = (new DateTimeImmutable('now'))->setDate($period['startYear'], $period['startMonth'], $period['startDay'])->setTime(0, 0);
                $end = $this->ShiftCustomBoundary($anchor, $period['type'], $period['duration'])->getTimestamp();
                if ($anchor->getTimestamp() < $now) {
                    $periods[$period['key']] = $this->CreateArchivePeriod($anchor->getTimestamp(), min($end, $now), $end);
                }
            }
        }
        if (count($periods) === 0) {
            throw new RuntimeException('No applicable periods.');
        }
        $start = min(array_column($periods, 'start'));
        $token = sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
        $job = ['status' => 'queued', 'variable' => $VariableID, 'processed' => 0, 'message' => '',
            'archive' => $archiveID, 'start' => $start, 'cursor' => $now, 'cutoff' => $now,
            'periods' => $periods, 'next' => null, 'newest' => null, 'live' => [], 'created' => $now, 'rebuild' => $rebuild,
            'type' => $rebuild ? 'rebuild' : 'merge', 'archiveSeen' => []];
        $this->WithLock(function () use ($token, $job): void {
            $jobs = $this->ReadMergeJobs();
            $jobs[$token] = $job;
            $this->WriteMergeJobs($jobs);
            $queue = $this->ReadMergeQueue();
            $queue[] = $token;
            $this->WriteMergeQueue($queue);
        });
        $this->SetTimerInterval(self::TIMER_MERGE, 1000);
        return $token;
    }

    private function CreateArchivePeriod(int $start, int $end, int $periodEnd): array
    {
        return ['start' => $start, 'end' => $end, 'periodEnd' => $periodEnd,
            'min' => null, 'max' => null, 'sum' => 0.0, 'count' => 0, 'carry' => null,
            'weightedSum' => 0.0, 'weightedTime' => 0, 'seeded' => false];
    }

    private function PublicJob(string $token, array $job): array
    {
        return ['token' => $token, 'type' => $job['type'] ?? (($job['rebuild'] ?? false) ? 'rebuild' : 'merge'),
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
            foreach ($job['periods'] as &$period) {
                // The next newer value defines the right edge of this sample's validity.
                $right = min($period['end'], $job['next']['timestamp'] ?? $job['cutoff']);
                $left = max($period['start'], $t);
                if ($right > $left) {
                    $period['weightedSum'] += $value * ($right - $left);
                    $period['weightedTime'] += $right - $left;
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
        if (($job['rebuild'] ?? false) && $job['processed'] === 0) {
            throw new RuntimeException('Archive contains no samples; rebuild was not committed.');
        }
        // The oldest available value carries back to the beginning of the period that contains it.
        // A period that ended before the first archived sample gets no synthetic average.
        if ($job['next'] !== null) {
            foreach ($job['periods'] as &$period) {
                $right = min($period['end'], $job['next']['timestamp']);
                if ($period['start'] !== 0 && !$period['seeded'] && $job['next']['timestamp'] < $period['end'] && $right > $period['start']) {
                    $period['weightedSum'] += $job['next']['value'] * ($right - $period['start']);
                    $period['weightedTime'] += $right - $period['start'];
                }
            }
            unset($period);
        }
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
        $rebuild = (bool) ($job['rebuild'] ?? false);
        $cutoff = (int) $job['cutoff'];
        $averageType = $this->GetAverageType($variableID);
        $definitions = $this->GetPeriodDefinitions();
        $state = $this->ReadState();
        $variableState = $state[$key] ?? ['periods' => []];
        $newestValue = isset($job['newest']) ? (float) $job['newest']['value'] : (float) GetValue($variableID);
        $averageReplaced = [];

        foreach ($job['periods'] as $name => $archive) {
            $entry = $variableState['periods'][$name] ?? null;
            if ($entry === null) {
                if ($archive['min'] === null) {
                    continue;
                }
                // Completed, non-repeating custom periods have no live initialization.
                $entry = [
                    'periodStart' => $archive['start'], 'periodEnd' => $archive['periodEnd'] ?? $archive['end'],
                    'min' => null, 'max' => null, 'averageType' => $averageType, 'average' => null
                ];
            }
            if ((int) $entry['periodStart'] !== (int) $archive['start']) {
                continue; // Do not overwrite a period that rolled over during import.
            }

            if ($rebuild) {
                // Atomic replacement at commit: deleted archive peaks must not survive.
                $entry['min'] = $archive['min'];
                $entry['max'] = $archive['max'];
            } else {
                // A seeded period is covered completely by the archive up to the cutoff.
                foreach (['min', 'max'] as $extreme) {
                    $candidate = $archive[$extreme];
                    $old = $archive['seeded'] ? null : ($entry[$extreme] ?? null);
                    if ($candidate !== null && $this->IsBetterExtreme($extreme, $candidate, $old)) {
                        $entry[$extreme] = $candidate;
                    }
                }
            }

            // An archive-derived average replaces the live one. Without archive data, a rebuild
            // carries the newest value forward in a running period and leaves a completed one empty.
            $average = $this->CreateArchiveAverage($averageType, $archive, $newestValue, $cutoff);
            if ($average === null && $rebuild && (int) ($archive['periodEnd'] ?? $archive['end']) > $cutoff) {
                $average = $this->CreateAverageState($averageType, $newestValue, $cutoff);
            }
            if ($average !== null || $rebuild) {
                $entry['averageType'] = $averageType;
                $entry['average'] = $average;
                $averageReplaced[$name] = true;
            }
            $variableState['periods'][$name] = $entry;
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
            if ($rebuild) {
                // Live updates were withheld during the rebuild and are applied now, including rollovers.
                $this->ApplyValue($variableState, $variableID, $definitions, $value, $timestamp, true);
                $this->RememberLastValue($variableState, $variableID, $value, $timestamp);
                continue;
            }
            // A merge processed live updates already. Extremes are idempotent; averages
            // need the samples again only where the archive replaced them.
            $normalized = $this->NormalizeValue($variableID, $value);
            foreach ($definitions as $definition) {
                $name = $definition['key'];
                $bounds = $this->GetPeriodBounds($definition, $timestamp);
                if ($bounds === null || !isset($variableState['periods'][$name])
                    || (int) $variableState['periods'][$name]['periodStart'] !== $bounds[0]) {
                    continue;
                }
                if (isset($averageReplaced[$name])) {
                    $this->UpdatePeriodEntry($variableState['periods'][$name], $averageType, $normalized, $timestamp);
                } else {
                    $this->UpdateExtremes($variableState['periods'][$name], $normalized, $timestamp);
                }
            }
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

    private function CreateArchiveAverage(string $type, array $archive, float $lastValue, int $cutoff): ?array
    {
        if ($type === 'arithmetic' && ($archive['count'] > 0 || ($archive['carry'] ?? null) !== null)) {
            return ['sum' => (float) $archive['sum'], 'count' => (int) $archive['count'], 'carry' => $archive['carry'] ?? null];
        }
        if ($type === 'timeweighted' && $archive['weightedTime'] > 0) {
            return ['weightedSum' => (float) $archive['weightedSum'], 'weightedTime' => (int) $archive['weightedTime'],
                'lastValue' => $lastValue, 'lastTimestamp' => $cutoff];
        }
        return null;
    }

    // Returns true if a rebuild owns the live update; merge jobs only copy it.
    private function RecordMergeLiveValue(int $variableID, float $value, int $timestamp): bool
    {
        $jobs = $this->ReadMergeJobs();
        $changed = false;
        $blocked = false;
        foreach ($jobs as &$job) {
            if ((int) $job['variable'] !== $variableID || !in_array($job['status'], ['queued', 'running'], true)) {
                continue;
            }
            if ($job['rebuild'] ?? false) {
                $blocked = true;
            }
            if ($timestamp >= $job['cutoff']) {
                $job['live'][] = ['timestamp' => $timestamp, 'value' => $value];
                $changed = true;
            }
        }
        unset($job);
        if ($changed) {
            $this->WriteMergeJobs($jobs);
        }
        return $blocked;
    }

    private function GetRebuildingVariables(): array
    {
        $blocked = [];
        foreach ($this->ReadMergeJobs() as $job) {
            if (($job['rebuild'] ?? false) && in_array($job['status'], ['queued', 'running'], true)) {
                $blocked[(int) $job['variable']] = true;
            }
        }
        return $blocked;
    }

    private function ReplayFailedRebuild(array $job): void
    {
        $variableID = (int) $job['variable'];
        if (!($job['rebuild'] ?? false) || count($job['live'] ?? []) === 0 || !IPS_VariableExists($variableID)) {
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
        $jobs = $this->ReadMergeJobs();
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
        $this->WriteMergeJobs($jobs);

        $queue = array_values(array_filter(
            $this->ReadMergeQueue(),
            static fn($token) => !isset($aborted[(string) $token])
        ));
        $this->WriteMergeQueue($queue);
        if (count($queue) === 0) {
            $this->SetTimerInterval(self::TIMER_MERGE, 0);
        }
    }

    private function ReadMergeJobs(): array
    {
        $value = json_decode($this->ReadAttributeString('MergeJobs'), true);
        return is_array($value) ? $value : [];
    }

    private function ReadMergeQueue(): array
    {
        $value = json_decode($this->ReadAttributeString('MergeQueue'), true);
        return is_array($value) ? $value : [];
    }

    private function WriteMergeJobs(array $jobs): void
    {
        $this->WriteAttributeString('MergeJobs', json_encode($jobs, JSON_PRESERVE_ZERO_FRACTION));
    }

    private function WriteMergeQueue(array $queue): void
    {
        $this->WriteAttributeString('MergeQueue', json_encode(array_values($queue)));
    }

    // Serializes all access to State and MergeJobs; Symcon runs messages, timers and calls in parallel.
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
        $firstDay = $this->ReadPropertyInteger('FirstDayOfWeek');
        if ($firstDay < 0 || $firstDay > 6) {
            return $this->CreateError('First day of week is invalid.', true);
        }

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

            $average = strtolower((string) ($row['Average'] ?? 'none'));
            if (!in_array($average, ['none', 'arithmetic', 'timeweighted'], true)) {
                return $this->CreateError('The configured average type is invalid.', true);
            }
        }

        $reserved = array_fill_keys(array_map('strtolower', self::BUILTIN_PERIODS), true);
        $seenNames = $reserved;
        foreach ($this->GetCustomPeriodDefinitions() as $period) {
            $name = trim((string) ($period['name'] ?? ''));
            if ($name === '') {
                return $this->CreateError('A custom time window has no name.', true);
            }

            $lowerName = strtolower($name);
            if (isset($seenNames[$lowerName])) {
                return $this->CreateError('Time window names must be unique and must not use a standard name.', true);
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

    private function GetPeriodDefinitions(): array
    {
        $periods = [
            [
                'key' => 'Day',
                'name' => 'Day',
                'kind' => 'builtin',
                'type' => 'daily'
            ],
            [
                'key' => 'Week',
                'name' => 'Week',
                'kind' => 'builtin',
                'type' => 'weekly',
                'weekday' => $this->ReadPropertyInteger('FirstDayOfWeek')
            ],
            [
                'key' => 'Month',
                'name' => 'Month',
                'kind' => 'builtin',
                'type' => 'monthly'
            ],
            [
                'key' => 'Year',
                'name' => 'Year',
                'kind' => 'builtin',
                'type' => 'yearly'
            ],
            [
                'key' => 'Alltime',
                'name' => 'Alltime',
                'kind' => 'builtin',
                'type' => 'alltime'
            ]
        ];

        foreach ($this->GetCustomPeriodDefinitions() as $period) {
            $periods[] = $period;
        }

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
            // Rows from earlier builds use their name until EnsureTimeWindowIDs assigns an ID.
            $name = trim((string) ($row['Name'] ?? ''));
            $id = (string) ($row['ID'] ?? '');
            $periods[] = [
                'key'      => preg_match(self::TIME_WINDOW_ID_PATTERN, $id) === 1 ? $id : $name,
                'name'     => $name,
                'kind'     => 'custom',
                'type'     => strtolower((string) ($row['Unit'] ?? '')),
                'duration' => (int) ($row['Duration'] ?? 1),
                'repeat'   => (bool) ($row['Repeat'] ?? true),
                'startDay' => (int) ($date['day'] ?? 0),
                'startMonth' => (int) ($date['month'] ?? 0),
                'startYear'  => (int) ($date['year'] ?? 0)
            ];
        }

        return $periods;
    }

    // Gives every time window a unique, immutable ID and moves statistics of earlier builds
    // from the window name to that ID. Returns true if the configuration was rewritten.
    private function EnsureTimeWindowIDs(): bool
    {
        $rows = json_decode($this->ReadPropertyString('TimeWindows'), true);
        if (!is_array($rows)) {
            return false;
        }

        $seen = [];
        $assigned = [];
        foreach ($rows as &$row) {
            $id = (string) ($row['ID'] ?? '');
            if (preg_match(self::TIME_WINDOW_ID_PATTERN, $id) !== 1 || isset($seen[$id])) {
                $id = 'tw_' . bin2hex(random_bytes(6));
                $row['ID'] = $id;
                // A list of pairs: names such as "2026" would turn into integer array keys.
                $assigned[] = ['name' => trim((string) ($row['Name'] ?? '')), 'id' => $id];
            }
            $seen[$id] = true;
        }
        unset($row);
        if (count($assigned) === 0) {
            return false;
        }

        $builtin = array_map('strtolower', self::BUILTIN_PERIODS);
        $this->WithLock(function () use ($assigned, $builtin): void {
            $state = $this->ReadState();
            foreach ($state as &$variableState) {
                foreach ($assigned as ['name' => $name, 'id' => $id]) {
                    if ($name === '' || in_array(strtolower($name), $builtin, true) || !isset($variableState['periods'][$name])) {
                        continue;
                    }
                    $variableState['periods'][$id] = $variableState['periods'][$name];
                    unset($variableState['periods'][$name]);
                }
            }
            unset($variableState);
            $this->WriteState($state);
        });

        IPS_SetProperty($this->InstanceID, 'TimeWindows', json_encode($rows));
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    private function IsValidPeriodDefinition(array $period): bool
    {
        if (($period['kind'] ?? '') !== 'custom') {
            return false;
        }
        if (!in_array($period['type'], ['day', 'week', 'month', 'year'], true)) {
            return false;
        }
        if (($period['duration'] ?? 0) < 1) {
            return false;
        }

        $day = (int) ($period['startDay'] ?? 0);
        $month = (int) ($period['startMonth'] ?? 0);
        $year = (int) ($period['startYear'] ?? 0);
        if (!checkdate($month, $day, $year)) {
            return false;
        }

        return true;
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

            if (isset($state[(string) $variableID])) {
                $this->PublishVariable($variableID, $state[(string) $variableID]);
            }
        }

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $ident = IPS_GetObject($childID)['ObjectIdent'];
            if (ctype_digit($ident) && !isset($wanted[(int) $ident])) {
                $this->UnregisterVariable($ident);
            }
        }

        $this->PruneState(array_keys($wanted), array_column($this->GetPeriodDefinitions(), 'key'));
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
        $needed = false;
        foreach ($variableIDs as $variableID) {
            if ($this->GetAverageType($variableID) === 'timeweighted') {
                $needed = true;
                break;
            }
        }
        $minutes = $this->ReadPropertyInteger('RefreshInterval');
        $this->SetTimerInterval(self::TIMER_REFRESH, $needed && $minutes > 0 ? $minutes * 60000 : 0);
    }

    // Starts periods that rolled over and applies changed average types. Must run under the lock.
    private function SynchronizeCurrentValues(array $variableIDs): void
    {
        $now = time();
        $blocked = $this->GetRebuildingVariables();
        $definitions = $this->GetPeriodDefinitions();
        $state = $this->ReadState();
        $changed = [];
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
            if ($this->EnsureCurrentPeriods($variableState, $variableID, $definitions, (float) $value, $now)) {
                $state[$key] = $variableState;
                $changed[] = $variableID;
            }
        }

        if (count($changed) > 0) {
            $this->WriteState($state);
            foreach ($changed as $variableID) {
                $this->PublishVariable($variableID, $state[(string) $variableID]);
            }
        }
    }

    private function EnsureCurrentPeriods(array &$variableState, int $variableID, array $definitions, float $currentValue, int $now): bool
    {
        $averageType = $this->GetAverageType($variableID);
        $currentValue = $this->NormalizeValue($variableID, $currentValue);
        // The value valid at a period boundary is the last observed one; without history it is the current value.
        $carry = $variableState['last']['value'] ?? $currentValue;
        $changed = false;
        foreach ($definitions as $period) {
            $bounds = $this->GetPeriodBounds($period, $now);
            if ($bounds === null) {
                continue;
            }
            $name = $period['key'];
            $entry = $variableState['periods'][$name] ?? null;
            if (!is_array($entry) || (int) ($entry['periodStart'] ?? -1) !== $bounds[0]) {
                $variableState['periods'][$name] = $period['type'] === 'alltime'
                    ? $this->CreatePeriodEntry($bounds, $currentValue, $now, $averageType)
                    : $this->CreatePeriodEntry($bounds, $carry, $bounds[0], $averageType);
                $changed = true;
            } elseif (($entry['averageType'] ?? 'none') !== $averageType) {
                $variableState['periods'][$name]['averageType'] = $averageType;
                $variableState['periods'][$name]['average'] = $this->CreateAverageState($averageType, $currentValue, $now);
                $changed = true;
            }
        }
        return $changed;
    }

    // Applies one sample to the given periods in memory. Returns true if published data changed.
    private function ApplyValue(array &$variableState, int $variableID, array $definitions, float $value, int $timestamp, bool $allowRollover): bool
    {
        $averageType = $this->GetAverageType($variableID);
        $value = $this->NormalizeValue($variableID, $value);
        // The value valid before this sample seeds a period that started in between.
        $carry = $variableState['last']['value'] ?? $value;
        $changed = false;
        foreach ($definitions as $period) {
            $bounds = $this->GetPeriodBounds($period, $timestamp);
            if ($bounds === null) {
                continue;
            }
            $name = $period['key'];
            $entry = $variableState['periods'][$name] ?? null;
            $entryStart = is_array($entry) ? (int) ($entry['periodStart'] ?? -1) : -1;
            if ($entryStart !== $bounds[0]) {
                // Never fall back to an older period.
                if (!$allowRollover || $entryStart > $bounds[0]) {
                    continue;
                }
                $variableState['periods'][$name] = $period['type'] === 'alltime'
                    ? $this->CreatePeriodEntry($bounds, $value, $timestamp, $averageType)
                    : $this->CreatePeriodEntry($bounds, $carry, $bounds[0], $averageType);
                $changed = true;
            }
            if ($this->UpdatePeriodEntry($variableState['periods'][$name], $averageType, $value, $timestamp)) {
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

    // A new period starts with the value that is valid at its beginning.
    // Its timestamp is the period boundary, even if the rollover is noticed later.
    private function CreatePeriodEntry(array $bounds, $value, int $timestamp, string $averageType): array
    {
        return [
            'periodStart' => $bounds[0],
            'periodEnd' => $bounds[1],
            'min' => ['value' => $value, 'timestamp' => $timestamp],
            'max' => ['value' => $value, 'timestamp' => $timestamp],
            'averageType' => $averageType,
            'average' => $this->CreateAverageState($averageType, $value, $timestamp)
        ];
    }

    private function UpdatePeriodEntry(array &$entry, string $averageType, $value, int $timestamp): bool
    {
        $changed = false;
        if (($entry['averageType'] ?? 'none') !== $averageType) {
            $entry['averageType'] = $averageType;
            $entry['average'] = $this->CreateAverageState($averageType, $value, $timestamp);
            $changed = true;
        }
        if ($this->UpdateExtremes($entry, $value, $timestamp)) {
            $changed = true;
        }
        if ($this->UpdateAverageState($entry, $averageType, $value, $timestamp)) {
            $changed = true;
        }
        return $changed;
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
        if (($period['kind'] ?? '') === 'custom') {
            return $this->GetCustomPeriodBounds($period, $timestamp);
        }

        $timezone = new DateTimeZone(date_default_timezone_get());
        $moment = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);

        switch ($period['type']) {
            case 'alltime':
                // Zero is a stable identity for the lifetime period, not a measured timestamp.
                return [0, 2147483647];

            case 'daily':
                $start = $moment->setTime(0, 0, 0);
                $end = $start->modify('+1 day');
                break;

            case 'weekly':
                $currentWeekday = (int) $moment->format('w');
                $daysBack = ($currentWeekday - $period['weekday'] + 7) % 7;
                $start = $moment->modify('-' . $daysBack . ' days')->setTime(0, 0, 0);
                $end = $start->modify('+7 days');
                break;

            case 'monthly':
                $start = $moment->setDate((int) $moment->format('Y'), (int) $moment->format('n'), 1)->setTime(0, 0, 0);
                $end = $start->modify('+1 month');
                break;

            case 'yearly':
                $start = $moment->setDate((int) $moment->format('Y'), 1, 1)->setTime(0, 0, 0);
                $end = $start->modify('+1 year');
                break;

            default:
                throw new RuntimeException('Unsupported period type.');
        }

        return [$start->getTimestamp(), $end->getTimestamp()];
    }

    private function GetCustomPeriodBounds(array $period, int $timestamp): ?array
    {
        $timezone = new DateTimeZone(date_default_timezone_get());
        $anchor = (new DateTimeImmutable('now', $timezone))
            ->setDate($period['startYear'], $period['startMonth'], $period['startDay'])
            ->setTime(0, 0, 0);
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
                $moment = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->setTime(0, 0, 0);
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
            'Data' => $this->CreatePublicData($variableState, $variableID)
        ];
        $json = json_encode($public, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $this->SetValue($ident, $json);
    }

    private function CreatePublicData(array $variableState, int $variableID): array
    {
        $result = [];
        $averageType = $this->GetAverageType($variableID);
        foreach ($this->GetPeriodDefinitions() as $period) {
            $entry = $variableState['periods'][$period['key']] ?? null;
            if ($entry === null) {
                continue;
            }
            $periodData = [
                'min' => $entry['min'] ?? null,
                'max' => $entry['max'] ?? null
            ];
            if ($averageType !== 'none') {
                $periodData['avg'] = $this->CalculateAverage($entry, $averageType);
            }
            $result[$period['name']] = $periodData;
        }
        return $result;
    }

    private function GetAverageType(int $variableID): string
    {
        foreach ($this->GetConfiguredVariables() as $row) {
            if ((int) ($row['Variable'] ?? 0) === $variableID) {
                $type = strtolower((string) ($row['Average'] ?? 'none'));
                return in_array($type, ['none', 'arithmetic', 'timeweighted'], true) ? $type : 'none';
            }
        }
        return 'none';
    }

    // The arithmetic average covers the samples within the period. Until the first one
    // arrives, the value carried in at the period start stands in for it.
    private function CreateAverageState(string $type, $value, int $timestamp): ?array
    {
        if ($type === 'arithmetic') {
            return ['sum' => 0.0, 'count' => 0, 'carry' => (float) $value];
        }
        if ($type === 'timeweighted') {
            return [
                'weightedSum' => 0.0,
                'weightedTime' => 0,
                'lastValue' => (float) $value,
                'lastTimestamp' => $timestamp
            ];
        }
        return null;
    }

    private function UpdateAverageState(array &$entry, string $type, $value, int $timestamp): bool
    {
        if ($type === 'none') {
            return false;
        }
        if (!isset($entry['average']) || !is_array($entry['average'])) {
            $entry['average'] = $this->CreateAverageState($type, $value, $timestamp);
        }

        if ($type === 'arithmetic') {
            $entry['average']['sum'] = (float) ($entry['average']['sum'] ?? 0.0) + (float) $value;
            $entry['average']['count'] = (int) ($entry['average']['count'] ?? 0) + 1;
            return true;
        }

        $lastTimestamp = (int) ($entry['average']['lastTimestamp'] ?? $timestamp);
        if ($timestamp < $lastTimestamp) {
            return false;
        }
        $duration = $timestamp - $lastTimestamp;
        if ($duration > 0) {
            $entry['average']['weightedSum'] = (float) ($entry['average']['weightedSum'] ?? 0.0)
                + (float) ($entry['average']['lastValue'] ?? $value) * $duration;
            $entry['average']['weightedTime'] = (int) ($entry['average']['weightedTime'] ?? 0) + $duration;
        }
        $entry['average']['lastValue'] = (float) $value;
        $entry['average']['lastTimestamp'] = $timestamp;
        return true;
    }

    private function CalculateAverage(array $entry, string $type): ?float
    {
        $average = $entry['average'] ?? null;
        if (!is_array($average)) {
            return null;
        }
        if ($type === 'arithmetic') {
            $count = (int) ($average['count'] ?? 0);
            if ($count > 0) {
                return (float) $average['sum'] / $count;
            }
            return isset($average['carry']) ? (float) $average['carry'] : null;
        }
        if ($type === 'timeweighted') {
            $weightedSum = (float) ($average['weightedSum'] ?? 0.0);
            $weightedTime = (int) ($average['weightedTime'] ?? 0);
            $lastTimestamp = (int) ($average['lastTimestamp'] ?? 0);
            $end = min(time(), (int) ($entry['periodEnd'] ?? time()));
            if ($lastTimestamp > 0 && $end > $lastTimestamp) {
                $duration = $end - $lastTimestamp;
                $weightedSum += (float) ($average['lastValue'] ?? 0.0) * $duration;
                $weightedTime += $duration;
            }
            return $weightedTime > 0 ? $weightedSum / $weightedTime : (isset($average['lastValue']) ? (float) $average['lastValue'] : null);
        }
        return null;
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
        if (!is_array($state)) {
            return [];
        }
        foreach ($state as $key => $variableState) {
            if (is_array($variableState) && !array_key_exists('periods', $variableState)) {
                // Earlier builds kept the periods directly below the variable.
                $state[$key] = ['periods' => $variableState];
            }
        }
        return $state;
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
