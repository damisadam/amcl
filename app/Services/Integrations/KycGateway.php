<?php

namespace App\Services\Integrations;

use App\Models\Customer;

/** e-KYC adapters: CNIC OCR, NADRA Verisys, face match/liveness, sanctions/PEP screening (all simulated). */
class KycGateway
{
    public function __construct(private IntegrationManager $manager) {}

    public function ocr(Customer $c, string $scenario = 'auto'): ProviderResponse
    {
        return $this->manager->call('cnic_ocr', 'extract', ['cnic' => $c->cnic, 'scenario' => $scenario], function ($req) use ($c, $scenario) {
            if ($scenario === 'fail') {
                return new ProviderResponse('failure', [], 'IMAGE_UNREADABLE', 'CNIC image could not be read. Please retake the photo in good light.');
            }

            return new ProviderResponse('success', [
                'name' => $c->full_name, 'father_name' => $c->father_husband_name, 'cnic' => $c->cnic,
                'date_of_birth' => $c->date_of_birth?->toDateString(), 'expiry_date' => $c->cnic_expiry_date?->toDateString(),
                'address' => $c->permanent_address, 'confidence' => 0.97,
            ], providerRef: Deterministic::ref('OCR', $c->cnic.'ocr'));
        }, $c);
    }

    public function nadra(Customer $c, string $scenario = 'auto'): ProviderResponse
    {
        $digit = Deterministic::lastDigit($c->cnic);
        $outcome = match ($scenario) {
            'pass' => 'match', 'fail' => 'mismatch', 'manual_review' => 'partial',
            default => match (true) { $digit === 8 => 'mismatch', $digit === 7 => 'partial', default => 'match' },
        };

        return $this->manager->call('nadra_verisys', 'verify_citizen', ['cnic' => $c->cnic, 'name' => $c->full_name, 'scenario' => $scenario], function () use ($c, $outcome) {
            $ref = Deterministic::ref('VRS', $c->cnic);

            return match ($outcome) {
                'match' => new ProviderResponse('success', ['match' => 'full', 'name_match' => true, 'dob_match' => true, 'cnic_status' => 'valid'], providerRef: $ref),
                'partial' => new ProviderResponse('success', ['match' => 'partial', 'name_match' => false, 'dob_match' => true, 'cnic_status' => 'valid',
                    'note' => 'Name spelling differs from NADRA record (simulated).'], providerRef: $ref),
                default => new ProviderResponse('failure', ['match' => 'none', 'cnic_status' => 'mismatch'], 'NADRA_MISMATCH',
                    'Identity details do not match NADRA records (simulated).', $ref),
            };
        }, $c);
    }

    public function liveness(Customer $c, string $scenario = 'auto'): ProviderResponse
    {
        $digit = Deterministic::lastDigit($c->cnic);
        $score = match (true) {
            $scenario === 'fail' => 0.31,
            $scenario === 'manual_review' || ($scenario === 'auto' && $digit === 7) => 0.64,
            default => Deterministic::number($c->cnic.'face', 90, 99) / 100,
        };

        return $this->manager->call('liveness', 'face_match', ['cnic' => $c->cnic, 'scenario' => $scenario], function () use ($c, $score) {
            $data = ['face_match_score' => $score, 'liveness' => $score >= 0.5 ? 'live' : 'spoof_suspected', 'threshold' => 0.80];

            return $score >= 0.5
                ? new ProviderResponse('success', $data, providerRef: Deterministic::ref('FACE', $c->cnic))
                : new ProviderResponse('failure', $data, 'LIVENESS_FAILED', 'Liveness check failed (simulated).');
        }, $c);
    }

    public function screen(string $name, ?string $cnic, ?\DateTimeInterface $dob = null, string $scenario = 'auto', $subject = null): ProviderResponse
    {
        $digit = Deterministic::lastDigit($cnic);
        $match = $scenario === 'aml_match' || ($scenario === 'auto' && ($digit === 9 || str_contains(strtoupper($name), 'PEP')));

        return $this->manager->call('aml_screening', 'screen', ['name' => $name, 'cnic' => $cnic, 'scenario' => $scenario], function () use ($name, $cnic, $match) {
            if (! $match) {
                return new ProviderResponse('success', ['result' => 'clear', 'risk' => 'low', 'lists_checked' => ['UNSC', 'NACTA 1st Schedule', 'PEP']],
                    providerRef: Deterministic::ref('AML', $cnic.$name));
            }

            return new ProviderResponse('success', [
                'result' => 'potential_match', 'risk' => 'high', 'lists_checked' => ['UNSC', 'NACTA 1st Schedule', 'PEP'],
                'hits' => [['list' => 'PEP list (SIMULATED DATA)', 'name' => strtoupper($name), 'score' => 0.86, 'category' => 'Domestic PEP – relative']],
            ], providerRef: Deterministic::ref('AML', $cnic.$name));
        }, $subject);
    }
}
