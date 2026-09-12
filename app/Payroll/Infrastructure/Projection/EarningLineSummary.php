<?php

declare(strict_types=1);

namespace App\Payroll\Infrastructure\Projection;

use App\Payroll\Domain\Currency;
use App\Payroll\Domain\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model: the current numbers of one earning line (table earning_lines), maintained by
 * the projector. Nothing writes to it except the projector, and it is disposable: it can be
 * rebuilt from the event stream.
 *
 * @property string $id
 * @property string $currency
 * @property int $system_value_minor
 * @property int $current_value_minor
 * @property int $adjustment_count
 * @property int|null $frozen_at_version
 * @property int $version
 * @property-read CarbonImmutable $calculated_at
 * @property-write DateTimeInterface $calculated_at
 * @property-read CarbonImmutable $last_event_at
 * @property-write DateTimeInterface $last_event_at
 */
final class EarningLineSummary extends Model
{
    protected $table = 'earning_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'currency',
        'system_value_minor',
        'current_value_minor',
        'adjustment_count',
        'frozen_at_version',
        'version',
        'calculated_at',
        'last_event_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'system_value_minor' => 'integer',
            'current_value_minor' => 'integer',
            'adjustment_count' => 'integer',
            'frozen_at_version' => 'integer',
            'version' => 'integer',
            'calculated_at' => 'immutable_datetime',
            'last_event_at' => 'immutable_datetime',
        ];
    }

    public function systemValue(): Money
    {
        return Money::fromMinorUnits($this->system_value_minor, Currency::from($this->currency));
    }

    public function currentValue(): Money
    {
        return Money::fromMinorUnits($this->current_value_minor, Currency::from($this->currency));
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at_version !== null;
    }
}
