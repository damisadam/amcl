<?php

namespace App\Livewire\Ops\Reports;

use App\Livewire\Concerns\HandlesActions;
use App\Services\Reports\CsvExport;
use App\Services\Reports\RegulatoryReports;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.ops')]
class Regulatory extends Component
{
    use HandlesActions;

    public string $tab = 'quality';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->need('reports.regulatory');
        $this->from = now()->startOfYear()->toDateString();
        $this->to = today()->toDateString();
    }

    public function export()
    {
        $r = new RegulatoryReports(['from' => $this->from, 'to' => $this->to]);
        [$h, $rows] = match ($this->tab) {
            'products' => [['Product', 'Structure', 'Accounts', 'Disbursed', 'Outstanding'], $r->productWise()],
            'raast' => [['Month', 'Purpose', 'Direction', 'Count', 'Value'], $r->raastAnnexure()],
            default => [['Classification', 'Accounts', 'Outstanding', 'Rate %', 'Required provision', 'Provision held'], $r->portfolioQuality()],
        };

        return CsvExport::download('regulatory-'.$this->tab, $h, collect($rows)->map(fn ($x) => array_values($x))->push([RegulatoryReports::DISCLAIMER]), ['from' => $this->from, 'to' => $this->to]);
    }

    public function render()
    {
        $r = new RegulatoryReports(['from' => $this->from, 'to' => $this->to]);

        return view('livewire.ops.reports.regulatory', [
            'quality' => $this->tab === 'quality' ? $r->portfolioQuality() : [],
            'productRows' => $this->tab === 'products' ? $r->productWise() : [],
            'outreach' => $this->tab === 'outreach' ? $r->outreach() : null,
            'raast' => $this->tab === 'raast' ? $r->raastAnnexure() : [],
            'complaints' => $this->tab === 'complaints' ? $r->complaints() : null,
            'disclaimer' => RegulatoryReports::DISCLAIMER,
        ]);
    }
}
