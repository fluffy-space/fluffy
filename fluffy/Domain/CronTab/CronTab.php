<?php

namespace Fluffy\Domain\CronTab;

use InvalidArgumentException;

class CronTab
{
    /**
     * 
     * @var CronTabItem[]
     */
    private static array $tasks = [];

    /**
     * 
     * @param callable|array $task 
     * @param string $schedule 
     * @param mixed $params 
     * @return void 
     */
    public static function schedule($task, string $schedule, bool $runOnStartup = false, ...$params)
    {
        self::$tasks[] = new CronTabItem($task, $schedule, $runOnStartup, $params);
    }

    /**
     * 
     * @return CronTabItem[] 
     */
    public static function getJobs(): array
    {
        return self::$tasks;
    }

    public static function getNextRun(string $expression, int $currentTime = 0): int
    {
        // sec min hr day(month) month day(week)
        $parts = explode(' ', $expression);
        $count = count($parts);
        if ($count === 5) {
            array_unshift($parts, '0');
            $count++;
        }
        if ($count !== 6) {
            // wrong format
            throw new InvalidArgumentException("Crontab expression is in wrong format: $expression.");
        }

        $nextTime = $currentTime === 0 ? time() : $currentTime;
        // calculate parts
        // parse timestamp -> year, month, day, day-week, hours, minutes, seconds
        // increment each starting from seconds to satisfy the expression.
        // Y-m-d H:i:s w
        // $originTime = $nextTime;
        // 1:4:59   1:5:00 
        // 1:5:00   1:6:00
        // 0 | 59   0 | 0

        // TIME:
        // seconds
        //                  next = now -> +1 (min == 0 ? track minute change : no minute change)
        // current minute:  next > now -> + (next - now) (no minute change)
        // next minute:     next < now -> - now + 60 + next (track minute change)
        // track: min change: m1 - changed, m0 - not changed

        // minutes
        //                  next = now -> m1 ? +0 : +1 (hc = now == 0 ? h1 : h0) track hrs
        // current hr:      next > now -> + (next - now) (h0)
        // next hr:         next < now -> - now + 60 + next (h1)

        // 4:59:59 5:00:00 5:56:59
        // 5:00:00 6:00:00 6:00:00
        // hours
        //                  next = now -> h1 ? +0 : +1 (dc = now == 0 ? d1: d0) track days

        // DATE:
        // day of month
        // month
        // day of week

        // seconds
        $minuteChanged = false;
        $nowSecond = $nextTime % 60;
        $nextSecond = 0;
        $isSecondStep = false;
        if ($parts[0] === '*') {
            $nextSecond = ($nowSecond + 1) % 60;
            $isSecondStep = true;
        } else {
            $subParts = explode('/', $parts[0]);
            if (count($subParts) > 1) {
                // */2 every n seconds
                $nthSecond = intval($subParts[1]);
                $mod = $nowSecond % $nthSecond;
                $nextSecond = $nowSecond + $nthSecond - $mod;
                if ($nextSecond > 59) {
                    $nextSecond = 0;
                }
                $isSecondStep = true;
            } else {
                // example: 2 on 2 second
                $nextSecond = intval($parts[0]);
            }
        }
        $nextTime += -$nowSecond + $nextSecond;
        if ($nextSecond <= $nowSecond) {
            $minuteChanged = true; // $nextTime % 60 === 0;
        }

        // minutes
        $hrChanged = false;
        $nowMinute = intdiv($nextTime, 60) % 60;
        $nextMinute = 0;
        $isMinuteStep = false;
        if ($parts[1] === '*') {
            $nextMinute = ($minuteChanged ? $nowMinute + 1 : $nowMinute) % 60;
            $isMinuteStep = true;
        } else {
            $subParts = explode('/', $parts[1]);
            if (count($subParts) > 1) {
                // */2 every n minutes
                $nthMinute = intval($subParts[1]);
                $mod = $nowMinute % $nthMinute;
                $nextMinute = $nowMinute + $nthMinute - $mod;
                // var_dump([$nowMinute, $nthMinute, $mod, $nextMinute]);
                if ($nextMinute > 59) {
                    $nextMinute = 0;
                }
                $isMinuteStep = true;
            } else {
                // example: 2 on 2 minute
                $nextMinute = intval($parts[1]);
            }
        }
        $nextTime += (-$nowMinute + $nextMinute) * 60;
        if ($nextMinute < $nowMinute || ($minuteChanged && $nextMinute === $nowMinute)) {
            $hrChanged = true; // $nextTime % 60 === 0;            
        }

        // hours
        $dayChanged = false;
        $hrSeconds = 60 * 60;
        $nowHour = intdiv($nextTime, $hrSeconds) % 24;
        $nextHour = 0;
        $isHourStep = false;
        if ($parts[2] === '*') {
            $nextHour = ($hrChanged ? $nowHour + 1 : $nowHour) % 24;
            $isHourStep = true;
        } else {
            $subParts = explode('/', $parts[2]);
            if (count($subParts) > 1) {
                // */2 every n hour
                $nthHour = intval($subParts[1]);
                $mod = $nowHour % $nthHour;
                $nextHour = $nowHour + $nthHour - $mod;
                // var_dump([$nowHour, $nthHour, $mod, $nextHour]);
                if ($nextHour > 23) {
                    $nextHour = 0;
                }
                $isHourStep = true;
            } else {
                // example: 2 on 2 hour
                $nextHour = intval($parts[2]);
            }
        }
        $nextTime += (-$nowHour + $nextHour) * $hrSeconds;
        if ($nextHour < $nowHour || ($hrChanged && $nextHour == $nowHour)) {
            $dayChanged = true;
        }
        $originDayChanged = $dayChanged;
        // branch here -> [day of month, day of week]
        $branchTime = $nextTime;
        $times = [];
        for ($dayType = 0; $dayType < 2; $dayType++) {
            $dayChanged = $originDayChanged;
            if (
                ($dayType === 0 && $parts[3] === '*' && $parts[5] !== '*')
                ||
                ($dayType === 1 && $parts[5] === '*')
            ) {
                continue;
            }
            // var_dump($dayType ? 'Month' : 'Week');
            $nextTime = $branchTime;

            // day of month
            $daysOfMonth = [
                31, 28, 31, 30, 31, 30,
                31, 31, 30, 31, 30, 31
            ];

            $daySeconds = $hrSeconds * 24;
            $monthChanged = false;
            $nowYear = intval(gmdate('Y', $nextTime));
            $isLeapYear = $nowYear % 4 === 0;
            $nextYear = $nowYear + 1;
            $nextYearIsLeap = $nextYear % 4 === 0;
            if ($isLeapYear) {
                $daysOfMonth[1] = 29;
            }
            $nowMonth = intval(gmdate('m', $nextTime)) - 1;
            $nowDay = intval(gmdate('d', $nextTime)) - 1;
            // print_r([$nowDay, $nowMonth, gmdate('Y-m-d H:i:s', $nextTime)]);
            $nextDay = $nowDay;
            $isDayStep = false;
            if ($dayType === 0) {
                if ($parts[3] === '*') {
                    $nextDay = ($dayChanged ? $nowDay + 1 : $nowDay);
                    if ($nextDay > $daysOfMonth[$nowMonth] - 1) {
                        $nextDay = 0;
                    }
                    $isDayStep = true;
                } else {
                    $subParts = explode('/', $parts[3]);
                    if (count($subParts) > 1) {
                        // */2 every n day of month
                        $nthDay = intval($subParts[1]);
                        $mod = $nowDay % $nthDay;
                        $nextDay = $nowDay + $nthDay - $mod;
                        // var_dump([$nowHour, $nthHour, $mod, $nextHour]);
                        if ($nextDay > $daysOfMonth[$nowMonth] - 1) {
                            $nextDay = 0;
                        }
                        $isDayStep = true;
                    } else {
                        // example: 2 on 2 day of month
                        $nextDay = intval($parts[3]) - 1;
                        if ($nextDay > 31) {
                            throw new InvalidArgumentException("Day of month can not be greater than 31.");
                        }
                        // print_r([$nowDay, $nextDay, $daysOfMonth[$nowMonth], $daysOfMonth[$nowMonth + 1], $dayChanged, $nowMonth, gmdate('w Y-m-d H:i:s', $nextTime)]);
                        if (
                            $nextDay > $daysOfMonth[$nowMonth] - 1
                            //||
                            //($dayChanged && $nextDay === $daysOfMonth[$nowMonth] - 1)
                        ) {
                            $nextTime += ($daysOfMonth[$nowMonth]) * $daySeconds;
                            // if ($nextDay > $daysOfMonth[$nowMonth + 1] - 1) {
                            //     $nextTime += ($daysOfMonth[$nowMonth + 1]) * $daySeconds;
                            //     $dayChanged = false;
                            // }
                            // print_r([$nowDay, $nextDay, $daysOfMonth[$nowMonth], $daysOfMonth[$nowMonth + 1], $dayChanged, $nowMonth, gmdate('w Y-m-d H:i:s', $nextTime)]);
                            // $monthChanged = true;                           
                        }
                        if ($nextDay === $nowDay && $dayChanged && $nextDay > $daysOfMonth[$nowMonth < 11 ? $nowMonth + 1 : 0] - 1) {
                            // echo "$nowDay, $nextDay" . PHP_EOL;
                            //$nextTime += ($daysOfMonth[$nowMonth < 11 ? $nowMonth + 1 : 0]) * $daySeconds;
                            // $monthChanged = true;
                        }
                    }
                }
                $nextTime += (-$nowDay + $nextDay) * $daySeconds;
                if ($nextDay < $nowDay || ($dayChanged && $nextDay == $nowDay)) {
                    $monthChanged = true;
                    if ($nextDay > $daysOfMonth[$nowMonth < 11 ? $nowMonth + 1 : 0] - 1) {
                        $nextTime += ($daysOfMonth[$nowMonth < 11 ? $nowMonth + 1 : 0]) * $daySeconds;
                    }
                }
            }
            $processDayOfWeek = true;
            $dayOfWeekOnly = false;
            while ($processDayOfWeek) {
                $processDayOfWeek = false;
                // day of week
                $nowDayOfWeek = intval(gmdate('w', $nextTime));
                // print_r([$nowDay, $nowMonth, gmdate('w Y-m-d H:i:s', $nextTime)]);
                $nextDayOfWeek = 0;
                // $isDayOfWeekStep = false;
                // $weekChanged = false;
                if ($dayType === 1) {
                    if ($parts[5] === '*') {
                        $nextDayOfWeek = ($dayChanged ? $nowDayOfWeek + 1 : $nowDayOfWeek);
                        if ($nextDayOfWeek > 6) {
                            $nextDayOfWeek = 0;
                        }
                        // $isDayOfWeekStep = true;
                    } else {
                        $subParts = explode('/', $parts[5]);
                        if (count($subParts) > 1) {
                            // */2 every n day of week
                            $nthDay = intval($subParts[1]);
                            $mod = $nowDayOfWeek % $nthDay;
                            $nextDayOfWeek = $nowDayOfWeek + $nthDay - $mod;
                            // var_dump([$nowHour, $nthHour, $mod, $nextHour]);
                            if ($nextDayOfWeek > 6) {
                                $nextDayOfWeek = 0;
                            }
                            // $isDayOfWeekStep = true;
                        } else {
                            // example: 2 on 2 day of month
                            $nextDayOfWeek = intval($parts[5]);
                        }
                    }
                    // print_r([$nowDayOfWeek, $nextDayOfWeek, gmdate('w Y-m-d H:i:s', $nextTime)]);
                    $dayChange = $nextDayOfWeek - $nowDayOfWeek;
                    $daysToAdd = $dayChange >= 0 ? $dayChange : 7 + $dayChange;
                    if ($dayChanged && $nextDayOfWeek === $nowDayOfWeek) {
                        $daysToAdd += 7;
                    }
                    $nextTime += $daysToAdd * $daySeconds;
                    // if ($nextDayOfWeek < $nowDayOfWeek || ($dayChanged && $nextDayOfWeek === $nowDayOfWeek)) {
                    // $weekChanged = true;
                    // print_r([$nowDay, $nextDay]);
                    $nextDay += $daysToAdd;
                    if ($nextDay > $daysOfMonth[$nowMonth] - 1) {
                        $nextDay -= $daysOfMonth[$nowMonth];
                        $nextTime -= $daysOfMonth[$nowMonth] * $daySeconds;
                        $monthChanged = true;
                    }
                    // }
                }
                if ($dayOfWeekOnly) {
                    break;
                }
                // print_r([$nowDay, $nextDay]);
                // month
                $yearChanged = false;
                $nextMonth = 0;
                $isMonthStep = false;
                if ($parts[4] === '*') {
                    $nextMonth = ($monthChanged ? $nowMonth + 1 : $nowMonth);
                    if ($nextMonth > 11) {
                        $nextMonth = 0;
                    }
                    $isMonthStep = true;
                } else {
                    $subParts = explode('/', $parts[4]);
                    if (count($subParts) > 1) {
                        // */2 every n month
                        $nthMonth = intval($subParts[1]);
                        $mod = $nowMonth % $nthMonth;
                        $nextMonth = $nowMonth + $nthMonth - $mod;
                        if ($nextMonth > 11) {
                            $nextMonth = 0;
                        }
                        $isMonthStep = true;
                    } else {
                        // example: 2 on 2 month
                        $nextMonth = intval($parts[4]) - 1;
                    }
                }
                $monthDiff = (-$nowMonth + $nextMonth);
                // now 6:
                // diff -5 (next: 1): -[2-6] 
                // diff  3 (next: 9): +[7-9]
                if ($monthDiff !== 0) {
                    $subtract = $monthDiff < 0;
                    $from = $subtract ? $nextMonth : $nowMonth;
                    $to = $subtract ? $nowMonth - 1 : $nextMonth - 1;
                    for ($i = $from; $i <= $to; $i++) {
                        $nextTime += $daysOfMonth[$i] * $daySeconds * ($subtract ? -1 : 1);
                    }
                    $processDayOfWeek = $dayType === 1;
                    if ($processDayOfWeek) {
                        $dayOfWeekOnly = true;
                        // reset day to 0
                        // print_r([$nowDay, $nextDay, gmdate('w Y-m-d H:i:s', $nextTime)]);
                        // print_r([$nowDay, $nextDay]);
                        $nextTime -= $nextDay * $daySeconds;
                        // print_r([$nowDay, $nextDay, gmdate('w Y-m-d H:i:s', $nextTime)]);
                        $nextDay = 0;
                        // print_r([$nextDay, gmdate('w Y-m-d H:i:s', $nextTime)]);
                    }
                }

                if ($nextMonth < $nowMonth || ($monthChanged && $nextMonth == $nowMonth)) {
                    $yearChanged = true;
                }
            }
            // print_r([$nowMonth, $nextMonth, $isLeapYear, $nowYear, $subtract, $from, $to, gmdate('Y-m-d H:i:s', $nextTime)]);
            // print_r([$yearChanged, $nowDay, $nextDay, gmdate('w Y-m-d H:i:s', $nextTime)]);
            if ($yearChanged) {
                // months of the current year
                //print_r([$yearChanged, $nowDay, $nextDay, $nowMonth, $nextMonth, gmdate('w Y-m-d H:i:s', $nextTime)]);


                // print_r([$nowYear, $nextYear, $nextYearIsLeap, $daysOfMonth]);
                for ($i = $nextMonth + 1; $i < 12; $i++) {
                    $nextTime += $daysOfMonth[$i] * $daySeconds;
                    // echo gmdate('w Y-m-d H:i:s', $nextTime) . PHP_EOL;
                }
                if ($nextYearIsLeap) {
                    $daysOfMonth[1] = 29;
                } else {
                    $daysOfMonth[1] = 28;
                }
                for ($i = 0; $i < $nextMonth + 1; $i++) {
                    $nextTime += $daysOfMonth[$i] * $daySeconds;
                    // echo gmdate('w Y-m-d H:i:s', $nextTime) . PHP_EOL;
                }
            }

            // flatten on * or n/* condition if any parent has changed
            $flattenChild = false;
            $flattenChild = $flattenChild || $yearChanged;
            if ($isMonthStep && $flattenChild) {
                // $nextTime -= $daysOfMonth[$nextMonth] * $daySeconds;
            }
            $flattenChild = $flattenChild || $nextMonth > $nowMonth;
            if ($isDayStep && $flattenChild) {
                $nextTime -= $nextDay * $daySeconds;
            }
            $flattenChild = $flattenChild || $nextDay > $nowDay;
            if ($isHourStep && $flattenChild) {
                $nextTime -= $nextHour * $hrSeconds;
            }
            $flattenChild = $flattenChild || $nextHour > $nowHour;
            if ($isMinuteStep && $flattenChild) {
                $nextTime -= $nextMinute * 60;
            }
            $flattenChild = $flattenChild || $nextMinute > $nowMinute;
            if ($isSecondStep && $flattenChild) {
                $nextTime -= $nextSecond;
            }
            $times[] = $nextTime;
        }
        // if ($times[0] !== $times[1]) {
        //     print_r($times);
        // }
        return min($times);
    }
}
