<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Payment;

use PTAdmin\Core\Tests\Fixtures\ServiceProtocol\{ProtocolTestCase, FakePaymentAdapter, FullPaymentAdapter};
use PTAdmin\Core\ServiceProtocol\{ProtocolException, Secret};
use PTAdmin\Payment\{PaymentRegistry, PaymentRegistration, PaymentGateway, PaymentDefinition, PaymentSceneDefinition,
    PaymentSupport, PaymentScene, PaymentTarget, PaymentInteractionDefinition, PaymentInteractionType, PaymentExecutor,
    PaymentOperation, PaymentProfile, PaymentReadiness, PaymentRequirements, PaymentContext, PaymentReference,
    CreatePaymentRequest, Money, PaymentInteraction, PaymentStatus, PaymentResult, PaymentLookup,
    PaymentRequiredInput, ProviderObjectReference, PaymentNotificationRequest, RefundRequest, RefundLookup,
    RefundResult, RefundStatus};

final class PaymentProtocolTest extends ProtocolTestCase
{
    private PaymentRegistry $registry;
    private PaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new PaymentRegistry($this->container, $this->availability);
        $this->gateway = new PaymentGateway($this->registry);
        $this->activate('plugin_a');
        $this->activate('plugin_b');
    }

    /** @param list<PaymentOperation>|null $operations @param list<string> $currencies */
    private function support(
        string $code = 'pc_qr',
        PaymentTarget $target = PaymentTarget::Pc,
        PaymentInteractionType $interaction = PaymentInteractionType::QrCode,
        ?array $operations = null,
        array $currencies = ['CNY'],
        ?PaymentExecutor $executor = null,
        array $methods = [],
        array $required = [],
        array $followUp = [],
    ): PaymentSupport {
        return new PaymentSupport($code, $target, new PaymentInteractionDefinition($interaction, $executor),
            $operations ?? [PaymentOperation::Create, PaymentOperation::Query, PaymentOperation::ParseNotify, PaymentOperation::AcknowledgeNotify],
            $currencies, $methods, $required, $followUp);
    }

    private function add(FakePaymentAdapter $adapter, array $supports, string $channel = 'alipay',
        string $owner = 'plugin_a', PaymentScene $scene = PaymentScene::Qr): PaymentReference
    {
        $registration = new PaymentRegistration();
        $registration->add(new PaymentDefinition($channel, '测试渠道', [new PaymentSceneDefinition($scene, '测试场景', $supports)]),
            $adapter, [new PaymentProfile('default', 'merchant-original')]);
        $this->registry->commit($owner, $registration->channels());
        return new PaymentReference($owner, $channel, 'default', $scene, 'merchant-original');
    }

    private function request(string $currency = 'CNY', int $amount = 100): CreatePaymentRequest
    {
        return new CreatePaymentRequest('attempt-1', 'create-attempt-1', new Money($amount, $currency),
            '测试订单', 'https://site.example/payment/notify');
    }

    public function testOnePluginHasSeveralChannelsAndScenesWithIndependentReadiness(): void
    {
        $alipay = new FakePaymentAdapter();
        $wechat = new FakePaymentAdapter();
        $wechat->ready = new PaymentReadiness(false, null, 'not_configured');
        $registration = new PaymentRegistration();
        $registration->add(new PaymentDefinition('alipay', '支付宝', [
            new PaymentSceneDefinition(PaymentScene::Qr, '扫码', [$this->support()]),
            new PaymentSceneDefinition(PaymentScene::Web, '网站', [$this->support('pc_form', interaction: PaymentInteractionType::FormSubmit)]),
        ]), $alipay, [new PaymentProfile('default', 'merchant-original')]);
        $registration->add(new PaymentDefinition('wechat_pay', '微信', [new PaymentSceneDefinition(PaymentScene::Qr, '扫码', [$this->support()])]),
            $wechat, [new PaymentProfile('default', 'merchant-original')]);
        $this->registry->commit('plugin_a', $registration->channels());
        self::assertCount(2, $this->registry->definitions()['plugin_a']);
        self::assertSame(0, $alipay->readinessCalls);
        $available = $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY'));
        self::assertCount(2, $available);
        self::assertSame('alipay', $available[0]->reference->channelCode);
        $all = $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY', availableOnly: false));
        self::assertCount(3, $all);
        self::assertFalse($all[2]->readiness->ready);
        self::assertSame('not_configured', $all[2]->readiness->reasonCode);
        self::assertSame(0, $alipay->writes + $wechat->writes + $alipay->queries + $wechat->queries);
    }

    public function testDiscoveryNeverBuildsACrossProductOfSupportCombinations(): void
    {
        $adapter = new FakePaymentAdapter();
        $this->add($adapter, [$this->support(), $this->support('mobile_redirect', PaymentTarget::MobileWeb, PaymentInteractionType::Redirect,
            currencies: ['USD'], methods: ['card'])], scene: PaymentScene::Web);
        self::assertCount(0, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'USD')));
        self::assertCount(0, $this->registry->discover(new PaymentRequirements(PaymentTarget::MobileWeb, 'CNY')));
        self::assertCount(0, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY', interactions: [PaymentInteractionType::Redirect])));
        self::assertCount(0, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY', paymentMethodTypes: ['card'])));
        self::assertCount(1, $this->registry->discover(new PaymentRequirements(PaymentTarget::MobileWeb, 'USD', paymentMethodTypes: ['card'])));
    }

    public function testNamespacedChannelsDoNotFallBackToAnotherPlugin(): void
    {
        $first = new FakePaymentAdapter();
        $second = new FakePaymentAdapter();
        $this->add($first, [$this->support()]);
        $reference = $this->add($second, [$this->support()], owner: 'plugin_b');
        self::assertCount(2, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY')));
        $this->gateway->create(new PaymentContext($reference, 'pc_qr', PaymentTarget::Pc), $this->request());
        self::assertSame(0, $first->writes);
        self::assertSame(1, $second->writes);
        $missing = new PaymentReference('plugin_b', 'missing', 'default', PaymentScene::Qr, 'merchant-original');
        $this->assertProtocolError('reference_missing', fn () => $this->gateway->query($missing, new PaymentLookup('attempt-1')));
    }

    public function testRegistrationChecksOperationsWithoutResolvingAdapters(): void
    {
        $registration = new PaymentRegistration();
        $definition = new PaymentDefinition('alipay', '支付宝', [new PaymentSceneDefinition(PaymentScene::Qr, '扫码', [$this->support()])]);
        $this->container->bind(FakePaymentAdapter::class, fn () => throw new \LogicException('声明查询不应解析适配器'));
        $registration->add($definition, FakePaymentAdapter::class, [new PaymentProfile('default', 'merchant-original')]);
        $this->registry->commit('plugin_a', $registration->channels());
        self::assertSame($definition, $this->registry->definitions()['plugin_a']['alipay']);
        $this->assertProtocolError('definition_conflict', fn () => $registration->add($definition, FakePaymentAdapter::class, [new PaymentProfile('default', 'merchant-original')]));
        $bad = new PaymentDefinition('bad', '错误声明', [new PaymentSceneDefinition(PaymentScene::Qr, '扫码', [$this->support(operations: PaymentOperation::cases())])]);
        $this->assertProtocolError('adapter_invalid', fn () => (new PaymentRegistration())->add($bad, FakePaymentAdapter::class, [new PaymentProfile('default', 'merchant-original')]));
    }

    public function testRefundOperationsMustBeDeclaredTogether(): void
    {
        $operations = [PaymentOperation::Create, PaymentOperation::Query, PaymentOperation::ParseNotify, PaymentOperation::AcknowledgeNotify, PaymentOperation::Refund];
        $definition = new PaymentDefinition('bad', '错误声明', [new PaymentSceneDefinition(PaymentScene::Qr, '扫码', [$this->support(operations: $operations)])]);
        $this->assertProtocolError('definition_invalid', fn () => (new PaymentRegistration())->add($definition, FullPaymentAdapter::class, [new PaymentProfile('default', 'merchant-original')]));
    }

    public function testStripeExecutorAndFollowUpMustBeAvailableWithExactVersion(): void
    {
        $stripe = new PaymentExecutor('stripe.elements', 'v1');
        $followUp = new PaymentInteractionDefinition(PaymentInteractionType::ClientInvoke, new PaymentExecutor('stripe.authentication', 'v1'));
        $reference = $this->add(new FakePaymentAdapter(), [$this->support('elements', interaction: PaymentInteractionType::ClientInvoke,
            currencies: ['USD', 'JPY'], executor: $stripe, methods: ['card'], followUp: [$followUp])], channel: 'stripe', scene: PaymentScene::Web);
        self::assertCount(0, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'USD')));
        self::assertCount(0, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'USD', executors: [new PaymentExecutor('stripe.elements', 'v2'), $followUp->executor])));
        self::assertCount(0, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'USD', executors: [$stripe])));
        self::assertCount(1, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'USD', executors: [
            new PaymentExecutor('stripe.elements', 'v1'), new PaymentExecutor('stripe.authentication', 'v1'),
        ])));
        $this->assertProtocolError('executor_unavailable', fn () => $this->gateway->create(new PaymentContext($reference, 'elements', PaymentTarget::Pc), $this->request('USD')));
    }

    public function testStripeCreateAndQueryCanRequireAuthenticationWithoutConvertingJpy(): void
    {
        $adapter = new FakePaymentAdapter();
        $executor = new PaymentExecutor('stripe.elements', 'v1');
        $adapter->status = PaymentStatus::RequiresAction;
        $adapter->interaction = PaymentInteraction::clientInvoke($executor, ['client_secret' => 'pi_fixture_secret', 'next_action' => 'authenticate']);
        $reference = $this->add($adapter, [$this->support('elements', interaction: PaymentInteractionType::ClientInvoke,
            currencies: ['USD', 'JPY'], executor: $executor, methods: ['card'])], channel: 'stripe', scene: PaymentScene::Web);
        $context = new PaymentContext($reference, 'elements', PaymentTarget::Pc, [$executor]);
        $created = $this->gateway->create($context, $this->request('JPY', 500));
        self::assertSame(500, $created->money->amountMinor);
        self::assertSame(PaymentStatus::RequiresAction, $created->status);
        self::assertStringNotContainsString('pi_fixture_secret', json_encode($created, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('pi_fixture_secret', print_r($created, true));
        self::assertSame('pi_fixture_secret', $created->interaction->toClientArray()['parameters']['client_secret']);
        $this->assertProtocolError('interaction_serialization_forbidden', fn () => serialize($created));
        $adapter->queryResult = $created;
        self::assertSame(PaymentStatus::RequiresAction, $this->gateway->query($reference, new PaymentLookup('attempt-1'), $context)->status);
        $adapter->queryResult = new PaymentResult(new PaymentLookup('attempt-1'), PaymentStatus::Processing, new Money(500, 'JPY'), 'merchant-original', PaymentInteraction::none());
        self::assertSame(PaymentStatus::Processing, $this->gateway->query($reference, new PaymentLookup('attempt-1'))->status);
        $adapter->queryResult = new PaymentResult(new PaymentLookup('attempt-1'), PaymentStatus::Succeeded, new Money(500, 'JPY'), 'merchant-original', PaymentInteraction::none(), new \DateTimeImmutable('2026-09-30T01:00:00Z'));
        self::assertSame(PaymentStatus::Succeeded, $this->gateway->query($reference, new PaymentLookup('attempt-1'))->status);
        self::assertSame('2026-09-30T01:00:00.000+00:00', $adapter->queryResult->jsonSerialize()['paid_at']);
    }

    public function testStripeCheckoutRedirectIsNotSuccessAndObjectReferencesArePreserved(): void
    {
        $adapter = new FakePaymentAdapter();
        $adapter->interaction = PaymentInteraction::redirect('https://checkout.stripe.com/c/pay/cs_fixture');
        $reference = $this->add($adapter, [$this->support('checkout', interaction: PaymentInteractionType::Redirect, currencies: ['USD'], methods: ['card'])], channel: 'stripe', scene: PaymentScene::Web);
        $created = $this->gateway->create(new PaymentContext($reference, 'checkout', PaymentTarget::Pc), $this->request('USD'));
        self::assertSame(PaymentStatus::Pending, $created->status);
        $session = new ProviderObjectReference('checkout_session', 'cs_fixture', 'checkout');
        $intent = new ProviderObjectReference('payment_intent', 'pi_fixture', 'payment');
        $charge = new ProviderObjectReference('charge', 'ch_fixture', 'charge');
        $adapter->queryResult = new PaymentResult(new PaymentLookup('attempt-1', [$session, $intent, $charge]), PaymentStatus::Processing,
            new Money(100, 'USD'), 'merchant-original', PaymentInteraction::none());
        self::assertCount(3, $this->gateway->query($reference, new PaymentLookup(objects: [$session]))->payment->objects);
        $this->assertProtocolError('result_invalid', fn () => $this->gateway->query($reference, new PaymentLookup(objects: [new ProviderObjectReference('checkout_session', 'cs_other', 'checkout')])));
    }

    public function testStaleDiscoveryDoesNotPermitCallsAfterDisableOrConfigurationDrift(): void
    {
        $adapter = new FakePaymentAdapter();
        $reference = $this->add($adapter, [$this->support()]);
        self::assertCount(1, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY')));
        $adapter->ready = new PaymentReadiness(true, 'merchant-other');
        self::assertCount(0, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY')));
        $this->assertProtocolError('merchant_changed', fn () => $this->gateway->create(new PaymentContext($reference, 'pc_qr', PaymentTarget::Pc), $this->request()));
        $this->assertProtocolError('merchant_changed', fn () => $this->gateway->query($reference, new PaymentLookup('attempt-1')));
        $adapter->ready = new PaymentReadiness(true, 'merchant-original');
        $this->lifecycle->transition('plugin_a', 'inactive');
        self::assertCount(0, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY', availableOnly: false)));
        $this->assertProtocolError('addon_unavailable', fn () => $this->gateway->query($reference, new PaymentLookup('attempt-1')));
        self::assertSame(0, $adapter->writes + $adapter->queries);
    }

    public function testChannelCanStopNewPaymentsWhileHistoricalQueriesRemainAvailable(): void
    {
        $adapter = new FakePaymentAdapter();
        $reference = $this->add($adapter, [$this->support()]);
        $adapter->ready = new PaymentReadiness(true, 'merchant-original', acceptingNewPayments: false);
        self::assertCount(0, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY')));
        $this->assertProtocolError('new_payments_disabled', fn () => $this->gateway->create(new PaymentContext($reference, 'pc_qr', PaymentTarget::Pc), $this->request()));
        self::assertNull($this->gateway->query($reference, new PaymentLookup('attempt-1')));
        self::assertCount(1, $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY', operations: [PaymentOperation::Query])));
    }

    public function testUncertainWritesAreNotRetriedOrMappedToPaymentFailure(): void
    {
        $adapter = new FakePaymentAdapter();
        $reference = $this->add($adapter, [$this->support()]);
        $request = $this->request();
        $adapter->failure = new ProtocolException('communication_failed', '厂商请求超时。', retryable: true, outcomeUncertain: true);
        $error = $this->assertProtocolError('communication_failed', fn () => $this->gateway->create(new PaymentContext($reference, 'pc_qr', PaymentTarget::Pc), $request));
        self::assertTrue($error->outcomeUncertain);
        self::assertSame(1, $adapter->writes);
        $adapter->failure = null;
        $this->gateway->create(new PaymentContext($reference, 'pc_qr', PaymentTarget::Pc), $request);
        self::assertSame('create-attempt-1', $adapter->requests[0]->idempotencyKey);
        self::assertSame($adapter->requests[0], $adapter->requests[1]);
    }

    public function testQueryNotFoundAndCommunicationFailureAreDifferent(): void
    {
        $adapter = new FakePaymentAdapter();
        $reference = $this->add($adapter, [$this->support()]);
        self::assertNull($this->gateway->query($reference, new PaymentLookup('attempt-1')));
        $adapter->failure = new ProtocolException('communication_failed', '查询失败。', retryable: true);
        $error = $this->assertProtocolError('communication_failed', fn () => $this->gateway->query($reference, new PaymentLookup('attempt-1')));
        self::assertTrue($error->retryable);
        self::assertFalse($error->outcomeUncertain);
    }

    public function testRequiredPayerAndUnsupportedCurrencyFailBeforeCreatingPayment(): void
    {
        $adapter = new FakePaymentAdapter();
        $reference = $this->add($adapter, [$this->support(required: [PaymentRequiredInput::PayerReference])]);
        $candidate = $this->registry->discover(new PaymentRequirements(PaymentTarget::Pc, 'CNY'))[0];
        self::assertTrue($candidate->preparationRequired);
        self::assertTrue($candidate->readiness->ready);
        $context = new PaymentContext($reference, 'pc_qr', PaymentTarget::Pc);
        $this->assertProtocolError('input_invalid', fn () => $this->gateway->create($context, $this->request()));
        $this->assertProtocolError('currency_unsupported', fn () => $this->gateway->create($context, $this->request('USD')));
        self::assertSame(0, $adapter->writes);
    }

    public function testNotificationKeepsExactBodyAndAcknowledgesOnlyHostCommittedOutcome(): void
    {
        $adapter = new FakePaymentAdapter();
        $reference = $this->add($adapter, [$this->support()]);
        $adapter->notificationResult = new PaymentResult(new PaymentLookup('attempt-1'), PaymentStatus::Succeeded, new Money(100, 'CNY'),
            'merchant-original', PaymentInteraction::none());
        $body = "{\n  \"id\": \"evt_fixture\"\n}";
        $request = new PaymentNotificationRequest($body, ['X-Test-Signature' => [hash_hmac('sha256', $body, 'fixture-key')]]);
        $event = $this->gateway->parseNotify($reference, $request);
        self::assertSame(PaymentStatus::Succeeded, $event->result->status);
        self::assertSame($event->eventId, $this->gateway->parseNotify($reference, $request)->eventId);
        self::assertSame(500, $this->gateway->acknowledgeNotify($reference, false)->status);
        self::assertSame('success', $this->gateway->acknowledgeNotify($reference, true)->body);
        $this->assertProtocolError('notification_verification_failed', fn () => $this->gateway->parseNotify($reference, new PaymentNotificationRequest(str_replace("\n", '', $body), $request->headers())));
        self::assertStringNotContainsString('evt_fixture', json_encode($request, JSON_THROW_ON_ERROR));
        $this->assertProtocolError('notification_serialization_forbidden', fn () => serialize($request));
    }

    public function testRefundHasExplicitOriginalAmountIdempotencyAndRefundedTime(): void
    {
        $adapter = new FullPaymentAdapter();
        $reference = $this->add($adapter, [$this->support(operations: PaymentOperation::cases())]);
        $request = new RefundRequest(new PaymentLookup('attempt-1'), 'refund-1', 'refund-key-1', new Money(40, 'CNY'),
            new Money(100, 'CNY'), 'https://site.example/refund/notify');
        $result = $this->gateway->refund($reference, $request);
        self::assertSame(40, $result->money->amountMinor);
        self::assertSame('refund-key-1', $adapter->requests[0]->idempotencyKey);
        self::assertNotNull($result->refundedAt);
        self::assertArrayNotHasKey('paid_at', $result->jsonSerialize());
        $adapter->refundResult = $result;
        self::assertSame($result, $this->gateway->queryRefund($reference, new RefundLookup($request->payment, 'refund-1')));
        $this->assertProtocolError('input_invalid', fn () => new RefundRequest($request->payment, 'refund-2', 'key-2', new Money(101, 'CNY'), new Money(100, 'CNY')));
        $this->assertProtocolError('input_invalid', fn () => new RefundRequest($request->payment, 'refund-2', 'key-2', new Money(1, 'USD'), new Money(100, 'CNY')));
    }

    public function testCloseCanDiscoverAlreadySucceededPayment(): void
    {
        $adapter = new FullPaymentAdapter();
        $reference = $this->add($adapter, [$this->support(operations: PaymentOperation::cases())]);
        $adapter->queryResult = new PaymentResult(new PaymentLookup('attempt-1'), PaymentStatus::Succeeded, new Money(100, 'CNY'), 'merchant-original', PaymentInteraction::none());
        self::assertSame(PaymentStatus::Succeeded, $this->gateway->close($reference, new PaymentLookup('attempt-1'))->status);
    }

    public function testResultCannotClaimWrongMerchantOrPaymentAttempt(): void
    {
        $adapter = new FakePaymentAdapter();
        $reference = $this->add($adapter, [$this->support()]);
        $adapter->queryResult = new PaymentResult(new PaymentLookup('other'), PaymentStatus::Pending, new Money(100, 'CNY'), 'merchant-original', PaymentInteraction::none());
        $this->assertProtocolError('result_invalid', fn () => $this->gateway->query($reference, new PaymentLookup('attempt-1')));
        $adapter->queryResult = new PaymentResult(new PaymentLookup('attempt-1'), PaymentStatus::Succeeded, new Money(100, 'CNY'), 'wrong-merchant', PaymentInteraction::none());
        $this->assertProtocolError('result_invalid', fn () => $this->gateway->query($reference, new PaymentLookup('attempt-1')));
    }

    public function testInteractionAndStatusValidationRejectsExecutableUrlsAndFalseTerminalActions(): void
    {
        $this->assertProtocolError('input_invalid', fn () => PaymentInteraction::redirect('javascript:alert(1)'));
        $this->assertProtocolError('input_invalid', fn () => PaymentInteraction::formSubmit('https://site.example/pay', ['field' => 1]));
        $this->assertProtocolError('result_invalid', fn () => new PaymentResult(new PaymentLookup('attempt-1'), PaymentStatus::Succeeded,
            new Money(100, 'CNY'), 'merchant-original', PaymentInteraction::qrCode('qr-value')));
        $this->assertProtocolError('result_invalid', fn () => new PaymentResult(new PaymentLookup('attempt-1'), PaymentStatus::RequiresAction,
            new Money(100, 'CNY'), 'merchant-original', PaymentInteraction::none()));
        $this->assertProtocolError('input_invalid', fn () => new Money(0, 'CNY'));
        $this->assertProtocolError('input_invalid', fn () => new Money(100, 'usd'));
        $this->assertProtocolError('secret_serialization_forbidden', fn () => serialize(new Secret('merchant-key')));
    }

    public function testRefundCannotMixCurrencyFromCreateOnlyCombinationWithAnotherRefundCombination(): void
    {
        $adapter = new FullPaymentAdapter();
        $reference = $this->add($adapter, [
            $this->support('cny_refundable', operations: PaymentOperation::cases()),
            $this->support('usd_create_only', currencies: ['USD']),
        ]);
        $this->assertProtocolError('currency_unsupported', fn () => $this->gateway->refund($reference,
            new RefundRequest(new PaymentLookup('attempt-1'), 'refund-1', 'key-1', new Money(10, 'USD'), new Money(100, 'USD'))));
        self::assertSame(0, $adapter->writes);
    }

    public function testQueryRejectsInteractionFromAnotherSupportCombination(): void
    {
        $adapter = new FakePaymentAdapter();
        $reference = $this->add($adapter, [$this->support(), $this->support('mobile_redirect', PaymentTarget::MobileWeb, PaymentInteractionType::Redirect)], scene: PaymentScene::Web);
        $adapter->queryResult = new PaymentResult(new PaymentLookup('attempt-1'), PaymentStatus::Pending, new Money(100, 'CNY'),
            'merchant-original', PaymentInteraction::redirect('https://provider.example/pay'));
        $this->assertProtocolError('result_invalid', fn () => $this->gateway->query($reference, new PaymentLookup('attempt-1'),
            new PaymentContext($reference, 'pc_qr', PaymentTarget::Pc)));
    }

    public function testFormAndQrInteractionsHaveExplicitClientFields(): void
    {
        $form = PaymentInteraction::formSubmit('https://provider.example/pay', ['signed_order' => 'fixture'], 'POST');
        self::assertSame(['signed_order' => 'fixture'], $form->toClientArray()['fields']);
        self::assertSame('POST', $form->toClientArray()['method']);
        self::assertArrayNotHasKey('fields', $form->jsonSerialize());
        $qr = PaymentInteraction::qrCode('weixin://wxpay/fixture');
        self::assertSame('weixin://wxpay/fixture', $qr->toClientArray()['content']);
        $this->assertProtocolError('input_invalid', fn () => PaymentInteraction::redirect('weixin://wxpay/fixture'));
    }

    public function testReadyPayerPreparationDoesNotCreateAPayment(): void
    {
        $adapter = new FullPaymentAdapter();
        $reference = $this->add($adapter, [$this->support(operations: PaymentOperation::cases())]);
        $result = $this->gateway->prepare(new PaymentContext($reference, 'pc_qr', PaymentTarget::Pc),
            new \PTAdmin\Payment\PreparePaymentRequest('https://site.example/payer/return'));
        self::assertTrue($result->ready);
        self::assertSame('payer-fixture', $result->payerReference);
        self::assertSame(0, $adapter->writes);
    }


    public function testInvalidRefundResultAfterWriteIsExplicitlyUncertain(): void
    {
        $adapter = new FullPaymentAdapter();
        $reference = $this->add($adapter, [$this->support(operations: PaymentOperation::cases())]);
        $lookup = new PaymentLookup('attempt-1');
        $adapter->refundResult = new RefundResult(new RefundLookup($lookup, 'refund-1'), RefundStatus::Succeeded,
            new Money(90, 'CNY'), 'merchant-original');
        $error = $this->assertProtocolError('result_invalid', fn () => $this->gateway->refund($reference,
            new RefundRequest($lookup, 'refund-1', 'key-1', new Money(40, 'CNY'), new Money(100, 'CNY'))));
        self::assertTrue($error->outcomeUncertain);
        self::assertFalse($error->retryable);
        self::assertSame(1, $adapter->writes);
    }

}
