<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException};

/** 通用输出不包含交互内容；宿主鉴权后明确交付当前付款人。 */
final class PaymentInteraction implements \JsonSerializable
{
    /** @param array<string,mixed> $payload */
    private function __construct(
        public readonly PaymentInteractionType $type,
        public readonly ?PaymentExecutor $executor,
        private readonly array $payload,
    ) {
    }

    public static function none(): self
    {
        return new self(PaymentInteractionType::None, null, []);
    }

    public static function qrCode(string $content): self
    {
        Input::nonEmpty($content, 'qr_content');
        return new self(PaymentInteractionType::QrCode, null, ['content' => $content]);
    }

    public static function redirect(string $url): self
    {
        Input::url($url, 'redirect_url');
        return new self(PaymentInteractionType::Redirect, null, ['url' => $url]);
    }

    /** @param array<string,string> $fields */
    public static function formSubmit(string $url, array $fields, string $method = 'POST'): self
    {
        Input::url($url, 'form_url');
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new ProtocolException('input_invalid', '支付表单只支持 GET 或 POST。');
        }
        Input::stringMap($fields, 'form_fields');
        return new self(PaymentInteractionType::FormSubmit, null, ['url' => $url, 'method' => $method, 'fields' => $fields]);
    }

    /** @param array<string,mixed> $parameters 厂商客户端参数；不得包含商户密钥或执行代码。 */
    public static function clientInvoke(PaymentExecutor $executor, array $parameters): self
    {
        json_encode($parameters, JSON_THROW_ON_ERROR);
        return new self(PaymentInteractionType::ClientInvoke, $executor, ['parameters' => $parameters]);
    }

    /** @return array<string,mixed> 调用方必须已核对付款人及当前支付尝试。 */
    public function toClientArray(): array
    {
        return ['type' => $this->type->value, 'executor' => $this->executor?->jsonSerialize(), ...$this->payload];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type->value, 'executor' => $this->executor?->jsonSerialize()];
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return array<never> */
    public function __serialize(): array
    {
        throw new ProtocolException('interaction_serialization_forbidden', '支付交互不能进入普通序列化；请使用受控客户端输出。');
    }
}
