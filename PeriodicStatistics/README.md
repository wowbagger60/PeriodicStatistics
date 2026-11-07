# PeriodicStatistics

[Deutsch](README.de.md) | English

Module for IP-Symcon by Ueli Schweizer / Cassanis Automation.
Prefix: `PERST`.

PeriodicStatistics computes statistics of integer and float variables over freely
definable time windows: minimum and maximum with timestamps, arithmetic and
time-weighted average, difference and integral. It is a general number cruncher,
not tied to energy, weather or any other domain.

## Table of contents

1. [Requirements](#1-requirements)
2. [Installation and first test](#2-installation-and-first-test)
3. [Time windows](#3-time-windows)
4. [Values](#4-values)
5. [Individual variables](#5-individual-variables)
6. [Rebuild from the archive](#6-rebuild-from-the-archive)
7. [Public functions](#7-public-functions)
8. [Notes and limitations](#8-notes-and-limitations)
9. [Support](#9-support)
10. [Changelog](#10-changelog)

## 1. Requirements

- IP-Symcon 8.1 or later.
- Archive logging for the source variables, if statistics are to be calculated
  from historical data.

## 2. Installation and first test

1. Install the library via the Module Store or Module Control and create a
   PeriodicStatistics instance.
2. Add the numeric source variables under «Variables» and apply.
3. Below the instance, a JSON variable with all values is created for each
   source variable.
4. If the source variables are logged in the archive: select «All variables» at
   the bottom of the configuration dialog and click «Rebuild from archive».
5. Optionally tick values in the edit dialog of a variable and the «Individual
   variables» column of a time window to create individual variables.

## 3. Time windows

Under «Time windows», each row defines one period. New instances start with
Day, Week, Month, Year and Decade, which can be changed or deleted like any other
window. Alltime is always recorded in addition.

| Column | Meaning |
|---|---|
| Name | Name in the JSON output, the functions and the individual variables |
| Start date | Beginning of the first period, at midnight |
| Duration, Unit | Length of a period, e.g. 1 week or 10 years |
| Repeat | Repeating window (e.g. every month) or a single fixed window (e.g. «Heating period 2026/27») |
| Individual variables | Create individual variables for this window |

The default windows start on 1 January 1970, except the week, which starts on
Monday, 5 January 1970. The weekday of the start date defines on which day a
week begins; for a week starting on Sunday, use 4 January 1970.

Each time window receives a hidden, immutable ID when the configuration is
applied. Statistics and individual variables are stored under this ID, so a
window can be renamed without losing data. Deleting a window removes its
statistics and individual variables; a window recreated with the same settings
starts empty. The name `Alltime` is reserved.

The «Refresh interval» republishes values that change with time even without
new samples: the time-weighted average and the integral (minutes, 0 = off).

## 4. Values

All values are always calculated and stored in the JSON variable of each source
variable.

| JSON key | Individual variable | Meaning |
|---|---|---|
| `min`, `max` | Minimum, Maximum | Extremes as `{ "value": ..., "timestamp": ... }`; optional timestamp variables |
| `avgArithmetic` | Average (arithmetic) | Mean of the value changes within the period |
| `avgTimeWeighted` | Average (time-weighted) | Each value weighted by how long it was valid |
| `delta` | Difference | Increase of a meter reading within the period |
| `integral` | Integral | Area under the curve in value × hours |

Not every value makes sense for every variable:

- **Minimum, maximum and the time-weighted average** suit measured values such
  as temperature, humidity or power.
- **Arithmetic average:** the mean of the value changes. Unchanged updates are
  not counted, matching what the archive stores with standard aggregation. A
  sensor that reports more often while the value moves fast is weighted
  accordingly; in most cases the time-weighted average is the better choice.
  Until the first change in a period arrives, the value valid at the period
  start is reported.
- **Difference:** meant for meter readings that only increase, such as energy,
  water, gas or rain counters. It sums the increases between consecutive values.
  A drop is treated as a meter reset or replacement: the new value counts as
  increase from zero. For other variables the difference has no useful meaning.
- **Integral:** meant for rates, such as power in W (integral in Wh), flow in
  l/h (integral in l) or rain rate in mm/h (integral in mm). For other
  variables, such as temperature, it has no useful meaning.

## 5. Individual variables

Select the values for each source variable in its edit dialog or directly in the
list, and the periods in the «Individual variables» column of the time windows
plus the «Individual variables for Alltime» switch. The number of variables is
sources × selected values × selected periods; the configuration dialog shows the
current total.

- «Times of minimum and maximum» adds a date/time variable for each selected
  extreme.
- Averages and the integral are always floats; minimum, maximum and difference
  have the type of the source.
- New variables take over the presentation of their source variable, except the
  integral, whose unit differs. Timestamps use `~UnixTimestamp`.
- Idents are `<sourceID>_<period>_<value>`, using the immutable ID for time
  windows. Renaming a source or time window renames generated names, but leaves
  names you changed yourself untouched.
- Deselecting a value or period removes its variables, including their archive
  data.

If a source variable is deleted, its statistics stop and the instance reports a
missing variable; all other variables keep running. Its outputs and statistics
remain until the row is removed from the configuration.

## 6. Rebuild from the archive

To calculate the statistics from archived data, for example after creating the
instance or adding a variable, select a variable or «All variables» at the
bottom of the configuration dialog and click «Rebuild from archive». The
statistics of the selected variables are replaced by values calculated from the
archive. The job list below shows the progress and is updated while the dialog
is open.

- Archive logging must be enabled for the source variable.
- Every rebuild reads the complete archive history of the variable, processed
  asynchronously in pages of 5000 records. Live updates during the rebuild are
  applied afterwards.
- Only one rebuild per variable can be pending. Pending rebuilds resume after a
  Symcon restart; applying the instance configuration aborts them.
- Completed jobs are removed from the list after one hour.

## 7. Public functions

Replace `$instanceID` and `$variableID` with your instance and source-variable
IDs.

```php
$values = PERST_GetValuesByVariable($instanceID, $variableID);
$all = PERST_GetValuesByVariable($instanceID, 0); // all source variables, keyed by ID
$period = PERST_GetValuesByPeriod($instanceID, 'Month');

// Rebuilds return a token and run asynchronously.
$token = PERST_RebuildFromArchive($instanceID, $variableID);
$status = PERST_GetJobStatus($instanceID, $token);
$jobs = PERST_GetJobs($instanceID);
```

Periods are addressed by their name, case-insensitively. Scripts that use a
period name must be adapted when the time window is renamed.

Job status reports `queued`, `running`, `finished` or `failed`, plus the
processed record count and a message.

Further functions:

| Function | Purpose |
|---|---|
| `PERST_ProcessValue($instanceID, $variableID, $periodName, $value, $timestamp)` | Applies a single value to one period |
| `PERST_ResetValue($instanceID, $variableID, $periodName, $extreme)` | Resets values of one period |

`PERST_ResetValue` accepts `min`, `max`, `aggregates` (averages, difference and
integral) or an empty string (everything). Reset extremes stay `null` until the
next value arrives. Reset aggregates of a running period restart with the
current value at the current time; for a completed period they become `null`.

## 8. Notes and limitations

- Time-weighted averages and integrals use the last observed value until the
  next observation or the period end.
- If logging was interrupted while a non-zero value was valid, that value counts
  for the whole gap. Insert a correcting value in the archive and rebuild the
  variable.
- Nothing is assumed before the first known value. A period that began earlier
  is weighted from that value on; a period that ended before the first archived
  sample receives no average. Without archive data, a new instance starts with
  the current value as of its last change.
- Distinct samples with identical timestamp and value in the same second cannot
  always be distinguished during live-update replay.
- A hard shutdown during a rebuild can lose buffered live updates; run a new
  rebuild if necessary.

## 9. Support

This module is free of charge. Donations to support the author are gratefully
accepted: PayPal to ueli.schweizer@cassanis.fr

## 10. Changelog

**1.0 Build 1:** Initial release.
