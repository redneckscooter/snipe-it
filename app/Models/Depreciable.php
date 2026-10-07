<?php

namespace App\Models;

use Carbon\Carbon;

class Depreciable extends SnipeModel
{
    /**
     * Depreciation Relation, and associated helper methods
     */

    // REQUIRES a purchase_date field
    //     and a purchase_cost field

    // REQUIRES a get_depreciation method,
    // which will return the deprecation.
    // this is needed because assets get
    // their depreciation from a model,
    // whereas licenses have deprecations
    // directly associated with them.
    // assets will override the following
    // two methods in order to inherit from
    // their model instead of directly (like
    // here)

    public function depreciation()
    {
        return $this->belongsTo(Depreciation::class, 'depreciation_id');
    }

    public function get_depreciation()
    {
        return $this->depreciation;
    }

    /**
     * Return the depreciated value as of a specific date.
     *
     * If no date is supplied, today's date is used.
     *
     * @param Carbon|null $asOfDate
     * @return float|int
     */
    public function getDepreciatedValue(?Carbon $asOfDate = null)
    {
        if (! $this->get_depreciation()) { // will never happen
            return $this->purchase_cost;
        }

        if ($this->get_depreciation()->months <= 0) {
            return $this->purchase_cost;
        }

        $asOfDate = $asOfDate ?: Carbon::now();
        $depreciation = 0;
        $setting = Setting::getSettings();
        switch ($setting->depreciation_method) {
            case 'half_1':
                $depreciation = $this->getHalfYearDepreciatedValue(true, $asOfDate);
                break;

            case 'half_2':
                $depreciation = $this->getHalfYearDepreciatedValue( false, $asOfDate);
                break;
            
            case 'diminish':
                $depreciation = $this->getDiminishingDepreciationValue();
                break;

            default:
                $depreciation = $this->getLinearDepreciatedValue($asOfDate);
                break;
        }

        return $depreciation;
    }

    /**
     * Return the linear depreciated value as of a specific date.
     *
     * @param Carbon|null $asOfDate
     * @return float|int|null
     */
    public function getLinearDepreciatedValue(?Carbon $asOfDate = null)
    {
        if (($this->get_depreciation()) && ($this->purchase_date)) {
            $asOfDate = $asOfDate ?: Carbon::now();
            /*
             * If the report date is before the purchase date,
             * the asset has not yet been purchased and therefore
             * has not depreciated.
             */
            if ($asOfDate->lt($this->purchase_date)) {
                return $this->purchase_cost;
            }

            $months_passed = ($this->purchase_date->diff($asOfDate)->m) + ($this->purchase_date->diff($asOfDate)->y * 12);

        } else {
            return null;
        }

        if ($months_passed >= $this->get_depreciation()->months) {
            // if there is a floor use it
            if ($this->get_depreciation()->depreciation_min) {
                $current_value = $this->calculateDepreciation();
            } else {
                $current_value = 0;
            }
        } else {
           // The equation here is (Purchase_Cost-Floor_min)*(Months_passed/Months_til_depreciated)
            $current_value = round(($this->purchase_cost -  ($this->purchase_cost - ($this->calculateDepreciation())) * ($months_passed / $this->get_depreciation()->months)), 2);
        }

        return $current_value;
    }

    public function getMonthlyDepreciation()
    {
        $setting = Setting::getSettings();
        if ($setting->depreciation_method === 'diminish') {
            return null;
        }
        return ($this->purchase_cost - $this->calculateDepreciation()) / $this->get_depreciation()->months;
    }

    /**
     * Calculate half-year depreciation as of a specific date.
     *
     * @param bool $onlyHalfFirstYear
     * @param Carbon|null $asOfDate
     * @return float|int
     */
    public function getHalfYearDepreciatedValue($onlyHalfFirstYear = false, ?Carbon $asOfDate = null) {
        /*
         * If no date was supplied, retain the normal Snipe-IT behavior
         * of using the current date.
         *  
         * @link http://www.php.net/manual/en/class.dateinterval.php
         */

        $asOfDate = $asOfDate ?: Carbon::now();
        $current_date = $this->getDateTime($asOfDate);
        $purchase_date = date_create($this->purchase_date);
        $currentYear = $this->get_fiscal_year($current_date);
        $purchaseYear = $this->get_fiscal_year($purchase_date);
        $yearsPast = $currentYear - $purchaseYear;
        $deprecationYears = ceil($this->get_depreciation()->months / 12);
        if ($onlyHalfFirstYear) {
            $yearsPast -= 0.5;
        } elseif (! $this->is_first_half_of_year($purchase_date)) {
            $yearsPast -= 0.5;
        }
        if (! $this->is_first_half_of_year($current_date)) {

            $yearsPast += 0.5;
        }
        if ($yearsPast >= $deprecationYears) {
            $yearsPast = $deprecationYears;
        } elseif ($yearsPast < 0) {
            $yearsPast = 0;
        }

        return $this->purchase_cost - round($yearsPast / $deprecationYears * $this->purchase_cost, 2);
    }
    
    public function getDiminishingDepreciationValue()
    {
        $depreciation = $this->get_depreciation();
        if (!$depreciation || !$this->purchase_date) {
            return null;
        }
        if ($depreciation->months <= 0) {
            return $this->purchase_cost;
        }
        $purchaseDate = $this->purchase_date->copy()->startOfDay();
        $currentDate = now()->startOfDay();
        if ($currentDate->lessThanOrEqualTo($purchaseDate)) {
            return $this->purchase_cost;
        }
        $baseValue = $this->purchase_cost;
        $effectiveLife = $depreciation->months / 12;
        $rateMultiplier = (float)($depreciation->rate_multiplier ?? 2);
        $fiscalYearStartMonth = (int)($depreciation->fiscal_year_start_month ?? 1);
        $diminishingRate = $rateMultiplier / $effectiveLife;
        $periodStart = $purchaseDate->copy();
        while ($periodStart->lessThanOrEqualTo($currentDate) && $baseValue > 0) {
            $fiscalYearStart = $periodStart->month >= $fiscalYearStartMonth
                ? $periodStart->copy()->setDate($periodStart->year, $fiscalYearStartMonth, 1)
                : $periodStart->copy()->setDate($periodStart->year - 1, $fiscalYearStartMonth, 1);
            $fiscalYearEnd = $fiscalYearStart->copy()->addYear()->subDay();
            $periodEnd = $currentDate->lessThan($fiscalYearEnd) ? $currentDate->copy() : $fiscalYearEnd;
            // Both the starting and ending dates count as days held.
            $daysHeld = $periodStart->diffInDays($periodEnd) + 1;
            $declineInValue = $baseValue * ($daysHeld / 365) * $diminishingRate;
            $baseValue -= min($declineInValue, $baseValue);
            $periodStart = $periodEnd->copy()->addDay();
        }

        return round(max($baseValue, 0), 2);
    }

    /**
     * @param \DateTime $date
     * @return int
     */
    protected function get_fiscal_year($date)
    {
        $year = intval($date->format('Y'));
        // also, maybe it'll have to set fiscal year date
        if ($date->format('nj') === '1231') {
            return $year;
        } else {
            return $year - 1;
        }
    }

    /**
     * @param \DateTime $date
     * @return bool
     */
    protected function is_first_half_of_year($date)
    {
        $date0m0d = intval($date->format('md'));
        return ($date0m0d < 601) || ($date0m0d >= 1231);
    }

    public function time_until_depreciated()
    {
        if ($this->depreciated_date()) {
            // @link http://www.php.net/manual/en/class.datetime.php
            $d1 = new \DateTime;
            $d2 = $this->depreciated_date();
            // @link http://www.php.net/manual/en/class.dateinterval.php
            $interval = $d1->diff($d2);
            if (! $interval->invert) {
                return $interval;
            } else {
                return new \DateInterval('PT0S'); // null interval (zero seconds from now)
            }
        }

        return false;
    }

    public function depreciated_date()
    {
        if (($this->purchase_date) && ($this->get_depreciation())) {
            $date = date_create($this->purchase_date);
            return date_add($date, date_interval_create_from_date_string($this->get_depreciation()->months.' months')); // date_format($date, 'Y-m-d'); //don't bake-in format, for internationalization
        }

        return null;
    }

    /**
     * Return depreciation progress percentage (0-100),
     * based on elapsed months since purchase date over
     * the depreciation window.
     *
     * This existing method intentionally continues to use
     * the current date. The report's date-specific calculation
     * uses getDepreciatedValue() instead.
     */
    public function depreciationProgressPercent(): float
    {
        if (! $this->purchase_date || ! $this->depreciated_date()) {
            return 0.0;
        }

        return $this->calculateProgressPercent(
            start: Carbon::parse($this->purchase_date),
            end: Carbon::instance($this->depreciated_date()),
        );
    }

    /**
     * Calculate elapsed/total month percentage and clamp to 0-100.
     */
    protected function calculateProgressPercent(Carbon $start, Carbon $end): float
    {
        $totalMonths = (float) $start->diffInMonths($end);
        if ($totalMonths <= 0) {
            return 0.0;
        }
        $elapsedMonths = (float) $start->diffInMonths(Carbon::now());
        $rawPercent = ($elapsedMonths / $totalMonths) * 100;
        return (float) min(100, max(0, $rawPercent));
    }

    // it's necessary for unit tests
    protected function getDateTime($time = null)
    {
        return new \DateTime($time);
    }

    private function calculateDepreciation()
    {
        if ($this->get_depreciation()->depreciation_type === 'percent') {
            $depreciation_percent = $this->get_depreciation()->depreciation_min / 100;
            $depreciation_min = $this->purchase_cost * $depreciation_percent;
            return $depreciation_min;
        }
        $depreciation_min = $this->get_depreciation()->depreciation_min;

        return $depreciation_min;
    }
}
