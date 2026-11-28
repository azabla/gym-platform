<?php

namespace Database\Factories;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\ExpenseCategory;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * Define the model's default state: a cash expense for the whole gym, paid today.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'expense_category_id' => fn (array $attributes): int => (int) ExpenseCategory::factory()->create(['organization_id' => $attributes['organization_id']])->getKey(),
            'amount' => Money::of((string) fake()->numberBetween(100, 5000)),
            'paid_on' => now('Africa/Addis_Ababa')->toDateString(),
            'method' => PaymentMethod::Cash,
        ];
    }
}
