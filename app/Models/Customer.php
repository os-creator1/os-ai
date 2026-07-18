<?php

namespace App\Models;

use App\Helpers\Helper;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;

/**
 * @method static where(string $string, string $uid)
 * @method static create(array $array)
 * @method truncate()
 * @method static count()
 * @method static thisYear()
 * @property mixed user
 * @property mixed subscription
 * @property mixed notifications
 */
class Customer extends Model
{
    use Notifiable;

    protected $table = 'customers';
    /**
     * @var mixed
     */
    private $can_edit;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $fillable = [
        'user_id',
        'contact_id',
        'parent',
        'company',
        'website',
        'address',
        'city',
        'postcode',
        'financial_address',
        'financial_city',
        'financial_postcode',
        'tax_number',
        'state',
        'country',
        'phone',
        'notifications',
        'permissions',
        'created_at',
        'updated_at',
        'uid',
    ];

    public static function boot()
    {
        parent::boot();

        // Create uid when creating list.
        static::creating(function ($item) {
            // Create new uid
            $uid = uniqid();
            while (self::where('uid', $uid)->count() > 0) {
                $uid = uniqid();
            }
            $item->uid = $uid;
        });
    }

    /**
     * Customer email.
     *
     * @return string
     */
    public function email(): string
    {
        return is_object($this->user) ? $this->user->email : '';
    }

    /**
     * Find item by uid.
     *
     * @param $uid
     *
     * @return object
     */
    public static function findByUid($uid): object
    {
        return self::where('uid', $uid)->first();
    }

    /**
     * Associations
     *
     * @return HasOne
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'user_id', 'user_id')->orderBy('created_at', 'desc');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }


    public function lists(): HasMany
    {
        return $this->hasMany(ContactGroups::class, 'customer_id', 'user_id')->orderBy('created_at', 'desc');
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'customer_id', 'user_id');
    }

    public function primaryBusiness(): HasOne
    {
        return $this->hasOne(Business::class, 'customer_id', 'user_id')->where('is_primary', true);
    }

    public function onboarding(): HasOne
    {
        return $this->hasOne(CustomerOnboarding::class, 'customer_id', 'user_id');
    }


    /**
     * Get active subscription.
     */
    public function activeSubscription()
    {
        return (is_object($this->subscription) && $this->subscription->active()) ? $this->subscription : null;
    }

    /**
     * Get customer options.
     *
     * @return array
     */
    public function getOptions(): array
    {
        if (is_object($this->activeSubscription())) {
            // Find plan
            return $this->activeSubscription()->plan->getOptions();
        } else {
            return [];
        }
    }

    /**
     * Get customer option.
     *
     * @param $name
     *
     * @return string|null
     */
    public function getOption($name): ?string
    {
        $options = $this->getOptions();

        return $options[$name] ?? null;
    }

    /**
     * Get max list quota.
     *
     * @return string|null
     */
    public function maxLists(): ?string
    {
        $count = $this->getOption('list_max');
        if ($count == -1) {
            return '∞';
        } else {
            return $count;
        }
    }

    /**
     * Count customer lists.
     *
     * @return int
     */
    public function listsCount(): int
    {
        return $this->lists()->count();
    }

    /**
     * Calculate list usage.
     *
     * @return int
     */
    public function listsUsage(): int
    {
        $max   = $this->maxLists();
        $count = $this->listsCount();

        if ($max == '∞') {
            return 0;
        }

        if ($max == 0) {
            return 0;
        }

        if ($count > $max) {
            return 100;
        }

        return round((($count / $max) * 100), 2);
    }

    /**
     * Calculate list usage.
     *
     * @return int
     */
    public function subscriberUsage(): int
    {
        $max   = $this->maxSubscribers();
        $count = $this->subscriberCounts();

        if ($max == '∞') {
            return 0;
        }

        if ($max == 0) {
            return 0;
        }

        if ($count > $max) {
            return 100;
        }

        return round((($count / $max) * 100), 2);
    }

    /**
     * Display calculate list usage.
     *
     * @return string
     */
    public function displayListsUsage(): string
    {
        if ($this->maxLists() == '∞') {
            return '∞';
        }

        return $this->listsUsage().'%';
    }

    /**
     * get total subscriber
     *
     * @return int
     */
    public function subscriberCounts(): int
    {
        return $this->hasMany(Contacts::class, 'customer_id', 'user_id')->count();
    }

    /**
     * get blacklist count
     *
     * @return int
     */
    public function blacklistCounts(): int
    {
        return $this->hasMany(Blacklists::class, 'user_id', 'user_id')->count();
    }

    /**
     * get sms template count
     *
     * @return int
     */
    public function smsTemplateCounts(): int
    {
        return $this->hasMany(Templates::class, 'user_id', 'user_id')->count();
    }

    /**
     * Get subscriber quota.
     *
     * @return string|null
     */
    public function maxSubscribers(): ?string
    {
        $count = $this->getOption('subscriber_max');
        if ($count == -1) {
            return '∞';
        } else {
            return $count;
        }
    }


    public function scopeThisYear($query)
    {
        return $query->where('created_at', '>=', Carbon::now()->firstOfYear());
    }

    /**
     * Display calculate list usage.
     *
     * @return string
     */
    public function displaySubscribersUsage(): string
    {
        if ($this->maxSubscribers() == '∞') {
            return '∞';
        }

        return $this->subscriberUsage().'%';
    }


    public function currentPlanName()
    {
        return is_object($this->activeSubscription()) ? $this->activeSubscription()->plan->name : __('locale.subscription.no_active_subscription');
    }


    /**
     * Get notifications.
     *
     * @return mixed
     */
    public function getNotifications()
    {
        if ( ! $this->notifications) {
            return json_decode('{}', true);
        }

        return json_decode($this->notifications, true);
    }

    /**
     * default customer permission
     *
     * @return false|string
     */
    public static function customerPermissions()
    {

        $existing_permissions = Helper::app_config('customer_permissions');
        if ($existing_permissions) {
            return $existing_permissions;
        }

        $categories = collect(config('customer-permissions'))->map(function ($value, $key) {
            $value['name'] = $key;

            return $value;
        })->groupBy('default')->first->toArray();

        $permissions = collect($categories)->map(function ($item) {
            return $item['name'];
        })->toArray();

        return json_encode($permissions);

    }


    /**
     * @param  Carbon|null  $timePoint
     *
     * @return mixed
     * @throws Exception
     */
    public function countUsage(Carbon $timePoint = null)
    {
        return $this->getQuotaTracker($timePoint)->add();
    }

    /**
     * Get customer's sending quota rate.
     *
     * @return string
     * @throws Exception
     */
    public function displaySendingQuotaUsage(): string
    {
        if ($this->getSendingQuota() == -1) {
            return __('locale.labels.unlimited');
        }

        // @todo use percentage helper here
        return $this->getSendingQuotaUsagePercentage().'%';
    }



    /**
     * get sms quota
     *
     * @return string|null
     */
    public function getSendingQuota()
    {
        // -1 indicate unlimited
        return $this->getOption('sms_max');
    }





    /**
     * Get customer's sending quota rate.
     *
     * @return float|int
     * @throws Exception
     */
    public function getSendingQuotaUsagePercentage()
    {
        $max   = $this->getSendingQuota();
        $count = $this->getSendingQuotaUsage();

        if ($max == -1) {
            return 0;
        }
        if ($max == 0) {
            return 0;
        }
        if ($count > $max) {
            return 100;
        }

        return round((($count / $max) * 100), 2);
    }


    /**
     * get sending limit
     *
     * @return array|null[]|string[]
     */
    public function getSendingLimits(): array
    {
        $timeValue = $this->getOption('sending_quota_time');
        if ($timeValue == -1) {
            return []; // no limit
        }
        $timeUnit = $this->getOption('sending_quota_time_unit');
        $limit    = $this->getOption('sending_quota');

        return ["{$timeValue} {$timeUnit}" => $limit];
    }


    public function getCurrentSubscription()
    {
        return $this->subscription;
    }





    /**
     * Get customer's quota.
     *
     * @return string
     */
    public function maxQuota()
    {
        $quota = $this->getOption('sending_quota');
        if ($quota == '-1') {
            return '∞';
        } else {
            return $quota;
        }
    }

}
