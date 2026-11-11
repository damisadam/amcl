<?php

namespace App\Services\Integrations;

use App\Models\SignatureRequest;

/** NIFT eSign adapter (simulated). Returns a signing reference and certificate details. */
class ESignGateway
{
    public function __construct(private IntegrationManager $manager) {}

    public function send(SignatureRequest $req): ProviderResponse
    {
        return $this->manager->call('nift_esign', 'create_envelope', ['document' => $req->documentType?->code, 'signer' => $req->signer_name], function () use ($req) {
            return new ProviderResponse('success', ['envelope_status' => 'sent'], providerRef: Deterministic::ref('NIFT', 'env'.$req->id));
        }, $req);
    }

    public function complete(SignatureRequest $req, string $documentHash, string $otpVerifiedVia = 'sms'): ProviderResponse
    {
        return $this->manager->call('nift_esign', 'sign', ['envelope' => $req->provider_ref, 'hash' => $documentHash, 'auth' => $otpVerifiedVia], function () use ($req, $documentHash) {
            return new ProviderResponse('success', [
                'signature_status' => 'signed',
                'certificate' => 'NIFT-SIMULATED-CA / '.$req->signer_name,
                'signed_hash' => $documentHash,
                'timestamp_authority' => 'SIMULATED-TSA',
            ], providerRef: $req->provider_ref);
        }, $req);
    }
}
