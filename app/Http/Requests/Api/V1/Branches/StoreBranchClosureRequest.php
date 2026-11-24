<?php

namespace App\Http\Requests\Api\V1\Branches;

use App\Domains\Organizations\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBranchClosureRequest extends FormRequest
{
    /** @var Collection<int, Branch>|null */
    private ?Collection $branches = null;

    /**
     * Owners and managers who work at every branch sent (ADR 0037).
     */
    public function authorize(): bool
    {
        foreach ($this->branches() as $branch) {
            if (! $this->user()->can('update', $branch)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One or more of the gym's branches, from one day to another (both
     * included), with a reason; closed all day, or open other hours given as
     * opens_at and closes_at (closing earlier than opening means after
     * midnight).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'distinct'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['required', 'string', 'max:100'],
            'opens_at' => ['nullable', 'date_format:H:i', 'required_with:closes_at'],
            'closes_at' => ['nullable', 'date_format:H:i', 'required_with:opens_at', 'different:opens_at'],
            'repeats_yearly' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Refuse branch numbers that aren't the gym's.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->branches()->count() !== count(array_unique((array) $this->input('branch_ids', [])))) {
                    $validator->errors()->add('branch_ids', 'Choose branches of this gym.');
                }
            },
        ];
    }

    /**
     * The branches sent, among the gym's.
     *
     * @return Collection<int, Branch>
     */
    public function branches(): Collection
    {
        $ids = array_filter((array) $this->input('branch_ids', []), 'is_numeric');

        return $this->branches ??= Branch::query()->whereKey(array_map('intval', $ids))->orderBy('name')->get();
    }

    public function startsOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('starts_on')->toString());
    }

    public function endsOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('ends_on')->toString());
    }
}
