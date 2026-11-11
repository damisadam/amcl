<?php

namespace App\Services\Integrations;

/**
 * Tasdeeq / Data Check adapter (simulated). Scores are deterministic functions of the CNIC and are
 * clearly labelled as simulated: they do NOT represent real bureau data.
 */
class CreditBureauGateway
{
    public function __construct(private IntegrationManager $manager) {}

    public function report(string $cnic, string $name, string $provider = 'tasdeeq', string $scenario = 'auto', $subject = null): ProviderResponse
    {
        return $this->manager->call($provider, 'credit_report', ['cnic' => $cnic, 'name' => $name, 'scenario' => $scenario], function () use ($cnic, $name, $scenario) {
            $seed = preg_replace('/\D/', '', $cnic);
            if ($scenario === 'fail') {
                return new ProviderResponse('error', [], 'BUREAU_UNAVAILABLE', 'Credit bureau timed out (simulated).');
            }
            if ($scenario === 'auto' && (crc32($seed) % 10) === 0) {
                return new ProviderResponse('success', ['status' => 'no_hit', 'score' => null, 'note' => 'No credit history found (thin file).', 'simulated' => true],
                    providerRef: Deterministic::ref('TSQ', $seed));
            }
            $score = $scenario === 'adverse' ? 480 : Deterministic::number($seed, 470, 840);
            $facilities = Deterministic::number($seed.'f', 0, 3);
            $obligations = $facilities * Deterministic::number($seed.'o', 2, 6) * 1000;
            $maxDpd = $score < 540 ? Deterministic::number($seed.'d', 60, 120) : ($score < 620 ? 30 : 0);

            return new ProviderResponse('success', [
                'status' => $score < 540 ? 'adverse' : 'clear',
                'score' => $score,
                'active_facilities' => $facilities,
                'total_outstanding' => $facilities * Deterministic::number($seed.'t', 40, 180) * 1000,
                'monthly_obligations' => $obligations,
                'max_dpd_12m' => $maxDpd,
                'enquiries_6m' => Deterministic::number($seed.'e', 0, 4),
                'subject_name' => $name,
                'simulated' => true,
            ], providerRef: Deterministic::ref('TSQ', $seed));
        }, $subject);
    }
}
