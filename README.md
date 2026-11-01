# PeriodicStatistics (PERST) — Version 1.0

IP-Symcon module for tracking minimum, maximum and optionally average values of integer and float variables over calendar and custom periods.

## Features

- Standard periods: Day, Week, Month, Year and Alltime.
- Configurable first day of the week.
- Custom time windows with start date, duration (days, weeks, months or years) and optional repetition.
- Minimum and maximum including timestamps.
- Optional arithmetic or time-weighted average for each source variable.
- One JSON output variable per configured source variable.
- Archive merge and full archive rebuild, processed asynchronously in pages of up to 5000 records.
- Live source updates are buffered while their archive job is rebuilding and replayed at commit.

## Configuration

Create a PeriodicStatistics instance. Add the numeric source variables in **Variables** and choose **None**, **Arithmetic**, or **Time weighted** for each. Set the first day of the week, the refresh interval for time-weighted averages (minutes, 0 = off) and, if needed, define custom time windows under **Custom time windows**.

Each custom time window receives a hidden, immutable ID when the configuration is applied. Statistics are stored under this ID, so a window can be renamed without losing data. Deleting a window removes its statistics, even if a new window with the same name is added later.

Each source variable receives a JSON output containing `VariableID` and `Data`. Period entries expose `min` and `max` as `{ "value": ..., "timestamp": ... }` (or `null`); `avg` is included when average calculation is enabled.

The arithmetic average is the mean of the value changes within the period; unchanged updates are not counted, matching what the archive stores with standard aggregation. Until the first change in a period arrives, the value valid at the period start is reported. The time-weighted average changes with time even without new values; the JSON output is therefore republished at the configured refresh interval, while the API always returns the current value.

If a source variable is deleted, its statistics stop and the instance reports a missing variable; all other variables keep running. Its output variable and statistics remain until the row is removed from the configuration.

## Public API

Replace `$instanceID` and `$variableID` with your instance and source-variable IDs.

```php
$values = PERST_GetValuesByVariable($instanceID, $variableID);
$all = PERST_GetValuesByVariable($instanceID, 0); // all source variables, keyed by ID
$period = PERST_GetValuesByPeriod($instanceID, 'Day');

// Archive jobs return a token and run asynchronously.
$token = PERST_RebuildFromArchive($instanceID, $variableID);
$status = PERST_GetJobStatus($instanceID, $token);
$jobs = PERST_GetJobs($instanceID);

// Alternatively merge archived values into existing statistics.
$token = PERST_MergeFromArchive($instanceID, $variableID);
```

Other public methods: `PERST_ProcessValue($instanceID, $variableID, $periodName, $value, $timestamp)` and `PERST_ResetValue($instanceID, $variableID, $periodName, $extreme)`.

`PERST_ResetValue` accepts `min`, `max`, `avg` or an empty string (all three). Reset extremes stay `null` until the next value arrives. A reset average of a running period restarts with the current value at the current time; for a completed period it becomes `null`.

Archive jobs require archive logging enabled for the source variable. Job status reports `queued`, `running`, `finished` or `failed`, plus the processed record count and message. Rebuild replaces period statistics from archive data; merge supplements existing statistics. Because the Alltime period is always included, every job reads the complete archive history of the variable. Pending jobs resume after a Symcon restart; applying the instance configuration aborts them. After manually editing archived records, reaggregate the source variable in the archive before rebuilding.

## Notes and limitations

- Time-weighted averages use the last observed value until the next observation or the applicable period end. Correct historical time boundaries and available archive samples matter.
- A historical period without sufficient archived data cannot necessarily be reconstructed accurately.
- Distinct samples with identical timestamp and value in the same second cannot always be distinguished during live-update replay.
- A hard shutdown during an archive rebuild can lose buffered live updates; run a new rebuild if necessary.
- A period that ended before the first archived sample receives no reconstructed average.
- Archive processing is asynchronous. Do not repeatedly start imports without a reason.

## Release

Version **1.0** (in development). Based on the validated 0.1 Build 4 Revision 5 implementation, with locking, batched updates, rollover carry-in and the fixes from the first review. Existing statistics are migrated automatically.
