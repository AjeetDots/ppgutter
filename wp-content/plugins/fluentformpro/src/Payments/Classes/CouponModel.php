<?php

namespace FluentFormPro\Payments\Classes;

use FluentForm\Framework\Helpers\ArrayHelper;
use FluentFormPro\Payments\PaymentHelper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class CouponModel
{
    private $table = 'fluentform_coupons';

    public function getCoupons($paginate = false)
    {
        $query = wpFluent()->table($this->table);
        if ($paginate) {
            if (!$perPage = intval($_REQUEST['per_page'])) {
                $perPage = 10;
            }
            $coupons = $query->paginate($perPage)->toArray();
            $coupons['data'] = $this->processGetCoupons($coupons['data']);
            return $coupons;
        }
        $coupons = $query->get();
        return $this->processGetCoupons($coupons);
    }

    public function getCouponByCode($code)
    {
        $coupon = wpFluent()->table($this->table)
            ->where('code', $code)
            ->first();
        if (!$coupon) {
            return $coupon;
        }

        $coupon->settings = $this->processSettings($coupon->settings);

        if ($coupon->start_date == '0000-00-00') {
            $coupon->start_date = '';
        }

        if ($coupon->expire_date == '0000-00-00') {
            $coupon->expire_date = '';
        }
        return $coupon;
    }

    public function getCouponsByCodes($codes)
    {
        $coupons = wpFluent()->table($this->table)
            ->whereIn('code', $codes)
            ->get();
        return $this->processGetCoupons($coupons);
    }

    public function insert($data)
    {
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');
        $data['created_by'] = get_current_user_id();

        if (isset($data['settings'])) {
            $data['settings'] = maybe_serialize($data['settings']);
        }

        return wpFluent()->table($this->table)
            ->insertGetId($data);
    }

    public function update($id, $data)
    {
        $data['updated_at'] = current_time('mysql');

        if (isset($data['settings'])) {
            $data['settings'] = maybe_serialize($data['settings']);
        }

        return wpFluent()->table($this->table)
            ->where('id', $id)
            ->update($data);
    }

    public function delete($id)
    {
        return wpFluent()->table($this->table)
            ->where('id', $id)
            ->delete();
    }

    public function getValidCoupons($coupons, $formId, $amountTotal)
    {
        $validCoupons = [];
        $userId = get_current_user_id();

        // Batch-fetch per-user usage counts for all limited coupons in one query
        $userCouponCounts = [];
        if ($userId) {
            $limitedCodes = [];
            foreach ($coupons as $coupon) {
                $couponLimit = ArrayHelper::get($coupon->settings, 'coupon_limit', 0);
                if ($couponLimit) {
                    $limitedCodes[] = $coupon->code;
                }
            }
            if ($limitedCodes) {
                $userCouponCounts = $this->batchCouponAppliedCounts($limitedCodes, $userId);
            }
        }

        // One grouped query for every max_use coupon instead of a COUNT per coupon.
        $globalCouponCounts = [];
        $maxUseCodes = [];
        foreach ($coupons as $coupon) {
            if ($coupon->max_use) {
                $maxUseCodes[] = $coupon->code;
            }
        }
        if ($maxUseCodes) {
            $globalCouponCounts = $this->batchCouponGlobalUsedCounts($maxUseCodes);
        }

        $otherCouponCodes = [];
        foreach ($coupons as $coupon) {
            if ($coupon->status != 'active') {
                continue;
            }

            if ($this->isDateExpire($coupon)) {
                continue;
            }

            if ($formIds = ArrayHelper::get($coupon->settings, 'allowed_form_ids')) {
                if (!in_array($formId, $formIds)) {
                    continue;
                }
            }

            if ($coupon->min_amount && $coupon->min_amount > $amountTotal) {
                continue;
            }

            $couponLimit = ArrayHelper::get($coupon->settings, 'coupon_limit', 0);
            if ($couponLimit) {
                if (!$userId) {
                    continue;
                }
                $used = isset($userCouponCounts[$coupon->code]) ? $userCouponCounts[$coupon->code] : 0;
                if ($used >= (int) $couponLimit) {
                    continue;
                }
            }

            if ($coupon->max_use) {
                // Not atomic with submission persistence: concurrent redemptions can both pass.
                $globalUsed = isset($globalCouponCounts[$coupon->code]) ? $globalCouponCounts[$coupon->code] : 0;
                if ($globalUsed >= (int) $coupon->max_use) {
                    continue;
                }
            }

            if ($otherCouponCodes && $coupon->stackable != 'yes') {
                continue;
            }

            $discountAmount = $coupon->amount;
            if ($coupon->coupon_type == 'percent') {
                $discountAmount = ($coupon->amount / 100) * $amountTotal;
            }

            $amountTotal = $amountTotal - $discountAmount;
            $otherCouponCodes[] = $coupon->code;

            $validCoupons[] = $coupon;
        }

        return $validCoupons;
    }

    public function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . $this->table;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            $sql = "CREATE TABLE $table (
				id int(11) NOT NULL AUTO_INCREMENT,
				title varchar(192),
				code varchar(192),
				coupon_type varchar(255) DEFAULT 'percent',
				amount decimal(10,2) NULL,
				status varchar(192) DEFAULT 'active',
				stackable varchar(192) DEFAULT 'no',
				settings longtext,
				created_by INT(11) NULL,
				min_amount INT(11) NULL,
				max_use INT(11) NULL,
				start_date date NULL,
				expire_date date NULL,
				created_at timestamp NULL,
				updated_at timestamp NULL,
				PRIMARY  KEY  (id)
			  ) $charsetCollate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            dbDelta($sql);
        }
    }

    public function isCouponCodeAvailable($code, $exceptId = false)
    {
        $query = wpFluent()->table($this->table)
            ->where('code', $code);
        if ($exceptId) {
            $query = $query->where('id', '!=', $exceptId);
        }
        return $query->first();
    }

    protected function processGetCoupons($coupons)
    {
        foreach ($coupons as $coupon) {
            if (!empty($coupon->settings)) {
                $coupon->settings = $this->processSettings($coupon->settings);
            } else {
                $coupon->settings = [
                    'allowed_form_ids' => [],
                    'coupon_limit'     => 0,
                ];
            }

            if ($coupon->start_date == '0000-00-00') {
                $coupon->start_date = '';
            }
            if ($coupon->expire_date == '0000-00-00') {
                $coupon->expire_date = '';
            }
        }
        return $coupons;
    }

    protected function processSettings($settings)
    {
        $settings = PaymentHelper::safeUnserialize($settings);

        $settings['coupon_limit'] = ArrayHelper::get($settings, 'coupon_limit', 0);

        return $settings;
    }

    public function hasLimit($couponCode, $couponLimit, $userId)
    {
        $couponApplied = $this->couponAppliedCount($couponCode, $userId);

        return (int) $couponLimit - $couponApplied > 0;
    }

    public function isDateExpire($coupon)
    {
        $start_date = '';
        $expire_date = '';

        if ($coupon->start_date && ("0000-00-00" != $coupon->start_date)) {
            $start_date =  strtotime($coupon->start_date);
        }
        if ($coupon->expire_date && ("0000-00-00" != $coupon->expire_date)) {
            $expire_date =  strtotime($coupon->expire_date);
        }

        $today = strtotime('today midnight');
        if ($start_date && $expire_date) {
            return !($start_date <= $today && $today <= $expire_date); // start-date<=today<=expire-date
        }
        if ($start_date) {
            return !($start_date <= $today);
        }
        if ($expire_date) {
            return !($today <= $expire_date);
        }
        return false;
    }

    protected function couponAppliedCount($couponCode, $userId)
    {
        $counts = $this->batchCouponAppliedCounts([$couponCode], $userId);
        return isset($counts[$couponCode]) ? $counts[$couponCode] : 0;
    }

    public function couponGlobalUsedCount($couponCode)
    {
        $counts = $this->batchCouponGlobalUsedCounts([$couponCode]);
        return isset($counts[$couponCode]) ? $counts[$couponCode] : 0;
    }

    protected function batchCouponGlobalUsedCounts(array $couponCodes)
    {
        $counts = array_fill_keys($couponCodes, 0);

        // Fast path: a non-stacked submission stores a single code, counted grouped.
        $exact = wpFluent()
            ->table('fluentform_entry_details')
            ->select(['field_value', wpFluent()->raw('COUNT(*) as usage_count')])
            ->where('field_name', 'payment-coupon')
            ->whereIn('field_value', $couponCodes)
            ->groupBy('field_value')
            ->get();
        foreach ($exact as $row) {
            $counts[$row->field_value] += (int) $row->usage_count;
        }

        $this->addStackedCouponCounts($counts, null);

        return $counts;
    }

    protected function batchCouponAppliedCounts(array $couponCodes, $userId)
    {
        $counts = array_fill_keys($couponCodes, 0);

        $exact = wpFluent()
            ->table('fluentform_entry_details')
            ->select(['fluentform_entry_details.field_value', wpFluent()->raw('COUNT(*) as usage_count')])
            ->where('fluentform_entry_details.field_name', 'payment-coupon')
            ->whereIn('fluentform_entry_details.field_value', $couponCodes)
            ->join('fluentform_submissions', function ($table) use ($userId) {
                $table->on('fluentform_submissions.id', '=', 'fluentform_entry_details.submission_id');
                $table->on('fluentform_submissions.user_id', '=', wpFluent()->raw(intval($userId)));
            })
            ->groupBy('fluentform_entry_details.field_value')
            ->get();
        foreach ($exact as $row) {
            $counts[$row->field_value] += (int) $row->usage_count;
        }

        $this->addStackedCouponCounts($counts, $userId);

        return $counts;
    }

    // Stacked coupons are persisted as one joined value ("A, B" via
    // Components/Coupon.php::addCouponsToSubmission, which implodes with ", ").
    // Split on that exact delimiter so a code containing a comma (e.g. "SAVE,20")
    // survives as one token and still counts toward max_use and per-user
    // coupon_limit instead of being shattered into unmatched fragments.
    // $userId === null counts global usage; an integer scopes to that user.
    protected function addStackedCouponCounts(array &$counts, $userId)
    {
        $codes = array_keys($counts);
        if (!$codes) {
            return;
        }
        $query = wpFluent()
            ->table('fluentform_entry_details')
            ->select(['fluentform_entry_details.field_value'])
            ->where('fluentform_entry_details.field_name', 'payment-coupon')
            ->where('fluentform_entry_details.field_value', 'LIKE', '%, %')
            ->where(function ($q) use ($codes) {
                // Prefilter: only joined rows that mention a requested code. The
                // authoritative match is the exact token comparison below.
                foreach ($codes as $code) {
                    $q->orWhere(
                        'fluentform_entry_details.field_value',
                        'LIKE',
                        '%' . addcslashes($code, '%_\\') . '%'
                    );
                }
            });
        if (null !== $userId) {
            $query->join('fluentform_submissions', function ($table) use ($userId) {
                $table->on('fluentform_submissions.id', '=', 'fluentform_entry_details.submission_id');
                $table->on('fluentform_submissions.user_id', '=', wpFluent()->raw(intval($userId)));
            });
        }
        foreach ($query->get() as $row) {
            foreach (array_map('trim', explode(', ', $row->field_value)) as $code) {
                if (isset($counts[$code])) {
                    $counts[$code]++;
                }
            }
        }
    }
}
