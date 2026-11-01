# PeriodicStatistics (PERST) — Version 1.0

IP-Symcon module that computes statistics of integer and float variables over freely definable time windows: minimum and maximum with timestamps, arithmetic and time-weighted average, difference and integral.

## Features

- Time windows with start date, duration (days, weeks, months or years) and optional repetition. New instances start with Day, Week, Month, Year and Decade, which can be changed or deleted like any other window.
- Alltime is always recorded in addition to the time windows.
- For every source variable and period: minimum and maximum with timestamps, arithmetic and time-weighted average, difference and integral.
- One JSON output variable per source variable with all values.
- Optional individual variables for selected values and periods, for use in the visualization and in events.
- Archive merge and full archive rebuild, processed asynchronously in pages of up to 5000 records.

## Configuration

Create a PeriodicStatistics instance and add the numeric source variables under **Variables**. Every value is always computed; the switches in the edit dialog of a variable only decide which individual variables are created.

Under **Time windows**, each row defines one period:

| Column | Meaning |
|---|---|
| Name | Name in the JSON output, the API and the individual variables |
| Start date | Beginning of the first period, at midnight |
| Duration, Unit | Length of a period, e.g. 1 week or 10 years |
| Repeat | Repeating window (e.g. every month) or a single fixed window (e.g. "Heating period 2026/27") |
| Individual variables | Create individual variables for this window |

The default windows start on 1 January 1970, except the week, which starts on Monday, 5 January 1970. The weekday of the start date defines on which day a week begins; for a week starting on Sunday, use 4 January 1970.

Each time window receives a hidden, immutable ID when the configuration is applied. Statistics and individual variables are stored under this ID, so a window can be renamed without losing data. Deleting a window removes its statistics and individual variables; a window recreated with the same settings starts empty. The name `Alltime` is reserved.

The **refresh interval** republishes values that change with time even without new samples: the time-weighted average and the integral (minutes, 0 = off).

## Values

| JSON key | Individual variable | Meaning |
|---|---|---|
| `min`, `max` | Minimum, Maximum | Extremes as `{ "value": ..., "timestamp": ... }`; optional timestamp variables |
| `avgArithmetic` | Average (arithmetic) | Mean of the value changes within the period |
| `avgTimeWeighted` | Average (time-weighted) | Each value weighted by how long it was valid |
| `delta` | Difference | Increase of a meter reading within the period |
| `integral` | Integral | Area under the curve in value × hours |

Not every value makes sense for every variable:

- **Minimum, maximum and the time-weighted average** suit measured values such as temperature, humidity or power.
- **Arithmetic average:** the mean of the value changes. Unchanged updates are not counted, matching what the archive stores with standard aggregation. A sensor that reports more often while the value moves fast is weighted accordingly; in most cases the time-weighted average is the better choice. Until the first change in a period arrives, the value valid at the period start is reported.
- **Difference:** meant for meter readings that only increase, such as energy, water, gas or rain counters. It sums the increases between consecutive values. A drop is treated as a meter reset or replacement: the new value counts as increase from zero. For other variables the difference has no useful meaning.
- **Integral:** meant for rates, such as power in W (integral in Wh) or flow in l/h (integral in l). For a power variable in W, the integral of a day is the energy of that day in Wh. For other variables, such as temperature, it has no useful meaning.

Values that make no sense for a variable are still computed and published in the JSON output; simply do not create individual variables for them.

### Individual variables

Select the values for each source variable in its edit dialog, and the periods in the **Individual variables** column of the time windows plus the **Alltime** switch. The number of variables is sources × selected values × selected periods; the configuration dialog shows the current total.

- **Timestamps** adds a date/time variable for each selected extreme.
- Averages and the integral are always floats; minimum, maximum and difference have the type of the source.
- New variables take over the presentation of their source variable, except the integral, whose unit differs. Timestamps use `~UnixTimestamp`.
- Idents are `<sourceID>_<period>_<value>`, using the immutable ID for time windows. Renaming a source or time window renames generated names, but leaves names you changed yourself untouched.
- Deselecting a value or period removes its variables, including their archive data.

If a source variable is deleted, its statistics stop and the instance reports a missing variable; all other variables keep running. Its outputs and statistics remain until the row is removed from the configuration.

## Public API

Replace `$instanceID` and `$variableID` with your instance and source-variable IDs.

```php
$values = PERST_GetValuesByVariable($instanceID, $variableID);
$all = PERST_GetValuesByVariable($instanceID, 0); // all source variables, keyed by ID
$period = PERST_GetValuesByPeriod($instanceID, 'Month');

// Archive jobs return a token and run asynchronously.
$token = PERST_RebuildFromArchive($instanceID, $variableID);
$status = PERST_GetJobStatus($instanceID, $token);
$jobs = PERST_GetJobs($instanceID);

// Alternatively merge archived values into existing statistics.
$token = PERST_MergeFromArchive($instanceID, $variableID);
```

Periods are addressed by their name, case-insensitively. Scripts that use a period name must be adapted when the time window is renamed.

Other public methods: `PERST_ProcessValue($instanceID, $variableID, $periodName, $value, $timestamp)` and `PERST_ResetValue($instanceID, $variableID, $periodName, $extreme)`.

`PERST_ResetValue` accepts `min`, `max`, `aggregates` (averages, difference and integral) or an empty string (everything). Reset extremes stay `null` until the next value arrives. Reset aggregates of a running period restart with the current value at the current time; for a completed period they become `null`.

Archive jobs require archive logging enabled for the source variable. Job status reports `queued`, `running`, `finished` or `failed`, plus the processed record count and message. Rebuild replaces period statistics from archive data; merge supplements extremes and replaces averages, difference and integral of periods the archive covers. Because Alltime is always included, every job reads the complete archive history of the variable. Pending jobs resume after a Symcon restart; applying the instance configuration aborts them. After manually editing archived records, reaggregate the source variable in the archive before rebuilding.

## Notes and limitations

- Time-weighted averages and integrals use the last observed value until the next observation or the period end. Correct historical time boundaries and available archive samples matter.
- A historical period without sufficient archived data cannot necessarily be reconstructed accurately. A period that ended before the first archived sample receives no reconstructed average.
- Distinct samples with identical timestamp and value in the same second cannot always be distinguished during live-update replay.
- A hard shutdown during an archive rebuild can lose buffered live updates; run a new rebuild if necessary.
- Archive processing is asynchronous. Do not repeatedly start imports without a reason.

## Release

Version **1.0** (in development). Statistics of earlier development builds are not migrated; run a rebuild from the archive after updating.
