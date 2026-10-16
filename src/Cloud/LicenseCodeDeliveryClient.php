<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use Illuminate\Contracts\Encryption\Encrypter;
use PTAdmin\Cloud\Contracts\CloudTransportInterface;
use PTAdmin\Core\ApplicationIdentity;

/** 授权码凭据独立加密保存，不依赖会员登录，也不进入任务载荷。 */
final class LicenseCodeDeliveryClient
{
    public function __construct(private readonly CloudTransportInterface $transport,
        private readonly ApplicationIdentity $identity, private readonly Encrypter $encrypter)
    {
    }

    public function authorize(string $code): array
    {
        $identity = $this->identity->load();
        $values = ['license_code' => $code, 'instance_id' => $identity['application_instance_id'],
            'public_key' => $identity['public_key'], 'timestamp' => time(), 'nonce' => bin2hex(random_bytes(32))];
        if (!openssl_sign(CloudProtocol::codeDeliveryPayload($values), $signature, $identity['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new CloudException('应用身份签名失败。', 'invalid_request');
        }
        $result = $this->transport->request('POST', '/code-delivery/authorize', $values + ['signature' => base64_encode($signature)]);
        if (!is_int($result['credential_id'] ?? null) || $result['credential_id'] < 1
            || !is_string($result['access_token'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $result['access_token']) !== 1) {
            throw new CloudException('授权码交付响应无效。', 'cloud_response_invalid');
        }
        $this->store($result['credential_id'])->put(['access_token' => $result['access_token'], 'instance_id' => $identity['application_instance_id']]);
        unset($result['access_token']);
        return $result;
    }

    public function request(int $credentialId, string $operation, array $selection = []): array
    {
        return $this->transport->request('POST', '/code-delivery/'.$operation, $selection, $this->token($credentialId));
    }

    public function download(int $credentialId, array $selection, string $destination): array
    {
        $token = $this->token($credentialId);
        $ticket = $this->transport->request('POST', '/code-delivery/ticket', $selection, $token);
        return (new VerifiedPackageDownloader($this->transport))->download($token, $ticket, $destination, '/code-delivery/download');
    }

    private function token(int $credentialId): string
    {
        $session = $this->store($credentialId)->get();
        if ($session === null || $session['instance_id'] !== $this->identity->load()['application_instance_id']) {
            throw new CloudException('授权码交付凭据不可用，请重新输入授权码。', 'code_credential_expired');
        }
        return $session['access_token'];
    }

    private function store(int $credentialId): EncryptedCloudSessionStore
    {
        return new EncryptedCloudSessionStore(storage_path('app/ptadmin/cloud/code-credentials/'.$credentialId.'.json'), $this->encrypter);
    }
}
