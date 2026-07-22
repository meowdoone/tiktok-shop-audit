<?php

namespace Laraditz\TikTok\Tests\Unit\Services;

use Laraditz\TikTok\Services\AuditService;
use Laraditz\TikTok\Tests\TestCase;
use Laraditz\TikTok\TikTok;

class AuditServiceTest extends TestCase
{
    public function test_local_audit_service_does_not_require_shop_credentials(): void
    {
        $service = TikTok::make(app_key: '', app_secret: '')->audit();

        $this->assertInstanceOf(AuditService::class, $service);
    }

    public function test_it_turns_shop_data_into_prioritized_business_issues(): void
    {
        $payload = json_decode(
            file_get_contents(__DIR__ . '/../../../examples/audit/sample-shop.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $report = (new AuditService(new TikTok()))->analyze($payload);
        $codes = array_column($report['issues'], 'code');

        $this->assertSame('P0', $report['issues'][0]['priority']);
        $this->assertSame('catalog.audit_failed', $report['issues'][0]['code']);
        $this->assertContains('catalog.incomplete_product', $codes);
        $this->assertContains('fulfillment.high_cancellation_rate', $codes);
        $this->assertContains('fulfillment.stale_open_orders', $codes);
        $this->assertContains('refund.high_order_rate', $codes);
        $this->assertContains('refund.finance_mismatch', $codes);
        $this->assertArrayHasKey('发生什么', $report['issues'][0]);
        $this->assertArrayHasKey('怎么改', $report['issues'][0]);
        $this->assertArrayHasKey('什么算完成', $report['issues'][0]);
        $this->assertSame(0.4, $report['metrics']['refund_order_rate']);
        $this->assertTrue($report['read_only']);
    }

    public function test_it_never_claims_a_refund_rate_without_all_orders(): void
    {
        $report = (new AuditService(new TikTok()))->analyze([
            'returns' => [
                ['id' => 'R-1', 'order_id' => 'O-1', 'reason' => 'DAMAGED', 'refund_amount' => 25],
            ],
        ]);

        $this->assertNull($report['metrics']['refund_order_rate']);
        $this->assertContains('refund_rate_unavailable', $report['boundaries']);
        $this->assertNotContains('refund.high_order_rate', array_column($report['issues'], 'code'));
    }

    public function test_it_normalizes_authorized_api_response_envelopes(): void
    {
        $report = (new AuditService(new TikTok()))->analyzeAuthorizedResponses([
            'products' => ['data' => ['products' => [
                ['id' => 'P-1', 'title' => 'Bottle', 'status' => 'ACTIVATE', 'category_id' => 'C-1', 'main_images' => [['uri' => 'synthetic://image']], 'skus' => [['id' => 'S-1', 'price' => ['amount' => 10], 'inventory' => [['quantity' => 5]]]]],
            ]]],
            'orders' => ['data' => ['orders' => [
                ['id' => 'O-1', 'status' => 'COMPLETED'],
                ['id' => 'O-2', 'status' => 'COMPLETED'],
            ]]],
            'returns' => ['data' => ['returns' => []]],
            'finance' => ['data' => ['transactions' => []]],
        ]);

        $this->assertSame(1, $report['coverage']['products']);
        $this->assertSame(2, $report['coverage']['orders']);
        $this->assertSame(0.0, $report['metrics']['refund_order_rate']);
    }

    public function test_authorized_responses_preserve_missing_dataset_boundaries(): void
    {
        $report = (new AuditService(new TikTok()))->analyzeAuthorizedResponses([
            'returns' => ['data' => ['returns' => [
                ['id' => 'R-1', 'order_id' => 'O-1', 'refund_amount' => 25],
            ]]],
        ]);

        $this->assertNull($report['metrics']['refund_order_rate']);
        $this->assertContains('refund_rate_unavailable', $report['boundaries']);
        $this->assertSame('unavailable', $report['dimensions']['catalog']['status']);
        $this->assertSame('unavailable', $report['dimensions']['fulfillment']['status']);
    }

    public function test_missing_return_response_is_not_reported_as_zero_refund_rate(): void
    {
        $report = (new AuditService(new TikTok()))->analyzeAuthorizedResponses([
            'orders' => ['data' => ['orders' => [
                ['id' => 'O-1', 'status' => 'COMPLETED'],
            ]]],
        ]);

        $this->assertNull($report['metrics']['refund_order_rate']);
        $this->assertNull($report['metrics']['refund_amount']);
        $this->assertContains('refund_rate_unavailable', $report['boundaries']);
        $this->assertSame('unavailable', $report['dimensions']['refund']['status']);
    }

    public function test_refund_rate_uses_only_order_ids_in_the_current_order_export(): void
    {
        $report = (new AuditService(new TikTok()))->analyze([
            'orders' => [['id' => 'O-1', 'status' => 'COMPLETED']],
            'returns' => [
                ['id' => 'R-1', 'order_id' => 'O-1', 'refund_amount' => 25],
                ['id' => 'R-2', 'order_id' => 'O-OUTSIDE-WINDOW', 'refund_amount' => 15],
            ],
        ]);

        $this->assertSame(1.0, $report['metrics']['refund_order_rate']);
    }

    public function test_it_flags_refunds_missing_from_a_provided_finance_export(): void
    {
        $report = (new AuditService(new TikTok()))->analyze([
            'orders' => [['id' => 'O-1', 'status' => 'COMPLETED']],
            'returns' => [['id' => 'R-1', 'order_id' => 'O-1', 'refund_amount' => 25]],
            'finance' => [],
        ]);

        $this->assertContains('refund.finance_mismatch', array_column($report['issues'], 'code'));
    }

    public function test_array_analysis_does_not_mutate_the_input(): void
    {
        $payload = [
            'products' => [['id' => 'P-1', 'title' => 'Synthetic Product']],
            'orders' => [],
        ];
        $before = $payload;

        (new AuditService(new TikTok()))->analyze($payload);

        $this->assertSame($before, $payload);
    }
}
