<?php

namespace App\Livewire\Customer\Products;

use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\Product;
use App\Services\Products\EligibilityService;
use App\Services\Products\FinancingCalculator;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Product detail with a live indicative calculator and eligibility hints (DFS §1.2, §2.2). */
#[Layout('layouts.customer')]
class Show extends Component
{
    use CustomerContext;

    public Product $product;

    public string $amount = '';

    public int $tenure = 12;

    public function mount(Product $product): void
    {
        abort_unless($product->is_active && $product->currentVersion, 404);
        $this->product = $product;
        $v = $product->currentVersion;
        $this->amount = (string) (int) round(((float) $v->min_amount + (float) $v->max_amount) / 2 / 1000) * 1000;
        $this->tenure = (int) max($v->min_tenure, min($v->max_tenure, $v->isWeekly() ? 26 : 12));
    }

    public function render(FinancingCalculator $calc, EligibilityService $eligibility)
    {
        $v = $this->product->currentVersion;
        $quote = null;
        $issues = [];
        $amount = is_numeric($this->amount) ? Money::of($this->amount) : '0.00';
        if (Money::gte($amount, $v->min_amount) && Money::lte($amount, $v->max_amount) && $this->tenure >= $v->min_tenure && $this->tenure <= $v->max_tenure) {
            $quote = $calc->quote($v, $amount, $this->tenure);
            $issues = $eligibility->check($this->customer(), $v, $amount, $this->tenure, null, false);
        } else {
            $issues[] = __('Choose an amount between :a and :b and a tenure between :c and :d.', ['a' => Money::format($v->min_amount), 'b' => Money::format($v->max_amount), 'c' => $v->min_tenure, 'd' => $v->max_tenure]);
        }

        return view('livewire.customer.products.show', [
            'v' => $v, 'quote' => $quote, 'issues' => $issues, 'customer' => $this->customer(),
            'docs' => $v->requiredDocuments()->with('documentType')->where('applies_to', 'applicant')->get(),
        ])->title($this->product->localizedName());
    }
}
