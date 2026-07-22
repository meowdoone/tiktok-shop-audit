<?php

namespace Laraditz\TikTok\Services;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Builds a read-only audit from TikTok Shop API responses or normalized arrays.
 *
 * This service never calls a mutation endpoint and never persists source rows.
 */
class AuditService extends BaseService
{
    public function analyzeAuthorizedResponses(array $responses): array
    {
        $input = [];

        if (array_key_exists('as_of', $responses)) {
            $input['as_of'] = $responses['as_of'];
        }
        if (array_key_exists('products', $responses)) {
            $input['products'] = $this->normalizeCollection($responses['products'], ['data.products', 'products']);
        }
        if (array_key_exists('orders', $responses)) {
            $input['orders'] = $this->normalizeCollection($responses['orders'], ['data.orders', 'orders']);
        }
        if (array_key_exists('returns', $responses) || array_key_exists('refunds', $responses)) {
            $returnResponse = array_key_exists('returns', $responses)
                ? $responses['returns']
                : $responses['refunds'];
            $input['returns'] = $this->normalizeCollection(
                $returnResponse,
                ['data.returns', 'data.return_orders', 'data.refunds', 'returns', 'refunds']
            );
        }
        if (array_key_exists('finance', $responses)) {
            $input['finance'] = $this->normalizeCollection(
                $responses['finance'],
                ['data.transactions', 'data.statement_transactions', 'data.payments', 'transactions', 'payments']
            );
        }

        return $this->analyze($input);
    }

    public function analyze(array $input): array
    {
        $productsProvided = array_key_exists('products', $input);
        $ordersProvided = array_key_exists('orders', $input);
        $returnsProvided = array_key_exists('returns', $input) || array_key_exists('refunds', $input);
        $financeProvided = array_key_exists('finance', $input);

        $products = $this->normalizeCollection($input['products'] ?? [], ['data.products', 'products']);
        $orders = $this->normalizeCollection($input['orders'] ?? [], ['data.orders', 'orders']);
        $returns = $this->normalizeCollection(
            $input['returns'] ?? $input['refunds'] ?? [],
            ['data.returns', 'data.return_orders', 'data.refunds', 'returns', 'refunds']
        );
        $finance = $this->normalizeCollection(
            $input['finance'] ?? [],
            ['data.transactions', 'data.statement_transactions', 'data.payments', 'transactions', 'payments']
        );
        $asOf = $this->asOf($input['as_of'] ?? null);

        $issues = array_merge(
            $this->catalogIssues($products, $productsProvided),
            $this->fulfillmentIssues($orders, $ordersProvided, $asOf)
        );
        $boundaries = [];

        $cancelledOrders = array_values(array_filter(
            $orders,
            fn(array $order): bool => in_array($this->status($order), ['CANCEL', 'CANCELED', 'CANCELLED'], true)
        ));
        $cancellationRate = count($orders) > 0 ? count($cancelledOrders) / count($orders) : null;

        $orderIds = [];
        foreach ($orders as $order) {
            $orderId = $this->firstValue($order, ['id', 'order_id', 'order.id', 'orderId']);
            if ($orderId !== null && $orderId !== '') {
                $orderIds[(string) $orderId] = true;
            }
        }

        $refundedOrderIds = [];
        foreach ($returns as $return) {
            $orderId = $this->firstValue($return, ['order_id', 'order.id', 'orderId']);
            if ($orderId !== null && $orderId !== '' && isset($orderIds[(string) $orderId])) {
                $refundedOrderIds[(string) $orderId] = true;
            }
        }
        $refundOrderRate = $ordersProvided && $returnsProvided && count($orders) > 0
            ? count($refundedOrderIds) / count($orders)
            : null;

        if (!$ordersProvided || count($orders) === 0 || !$returnsProvided) {
            $boundaries[] = 'refund_rate_unavailable';
        }
        if (!$financeProvided) {
            $boundaries[] = 'finance_reconciliation_unavailable';
        }

        $issues = array_merge(
            $issues,
            $this->refundIssues($returns, $finance, $refundOrderRate, $ordersProvided, $financeProvided)
        );

        usort($issues, function (array $left, array $right): int {
            $rank = ['P0' => 0, 'P1' => 1, 'P2' => 2];
            $priority = ($rank[$left['priority']] ?? 9) <=> ($rank[$right['priority']] ?? 9);

            return $priority !== 0 ? $priority : strcmp($left['code'], $right['code']);
        });

        return [
            'read_only' => true,
            'as_of' => $asOf->format(DateTimeInterface::ATOM),
            'coverage' => [
                'products' => count($products),
                'orders' => count($orders),
                'returns' => count($returns),
                'finance_rows' => count($finance),
            ],
            'metrics' => [
                'cancellation_rate' => $cancellationRate === null ? null : round($cancellationRate, 4),
                'refund_order_rate' => $refundOrderRate === null ? null : round($refundOrderRate, 4),
                'refund_amount' => $returnsProvided ? round($this->sumRefundAmounts($returns), 2) : null,
            ],
            'dimensions' => $this->dimensionSummary($issues, [
                'catalog' => $productsProvided,
                'health' => $productsProvided || $ordersProvided,
                'fulfillment' => $ordersProvided,
                'refund' => $returnsProvided,
            ]),
            'issues' => $issues,
            'boundaries' => array_values(array_unique($boundaries)),
        ];
    }

    private function catalogIssues(array $products, bool $provided): array
    {
        if (!$provided) {
            return [$this->issue(
                'P1',
                'health',
                'health.missing_product_data',
                '没有提供商品全集，无法判断目录完整度、审核状态和 SKU 健康度。',
                '导入已授权 Product API 列表或 Seller Center 商品导出，保留商品、类目、图片、SKU、价格、库存和审核状态字段。',
                '报告覆盖全部在售与待审核商品，并显示明确的商品总数和数据时间。',
                ['products' => 0]
            )];
        }

        $failedAudit = [];
        $incomplete = [];
        $unavailableSkus = [];

        foreach ($products as $index => $product) {
            $productId = (string) ($this->firstValue($product, ['id', 'product_id']) ?? 'row-' . ($index + 1));
            $auditStatus = strtoupper((string) ($this->firstValue($product, ['audit.status', 'audit_status']) ?? ''));
            if (in_array($auditStatus, ['FAILED', 'REJECTED', 'FAIL'], true)) {
                $failedAudit[] = $productId;
            }

            $title = trim((string) ($this->firstValue($product, ['title', 'product_name']) ?? ''));
            $category = $this->firstValue($product, ['category_id', 'category.id', 'category']);
            $images = $this->firstValue($product, ['main_images', 'images']);
            $skus = $this->normalizeCollection($this->firstValue($product, ['skus', 'sku_list']) ?? [], []);

            if ($title === '' || empty($category) || !is_array($images) || count($images) === 0 || count($skus) === 0) {
                $incomplete[] = $productId;
            }

            $hasHealthySku = false;
            foreach ($skus as $sku) {
                if ($this->amount($this->firstValue($sku, ['price.amount', 'price', 'sale_price'])) > 0
                    && $this->inventoryQuantity($sku) > 0) {
                    $hasHealthySku = true;
                    break;
                }
            }

            if (count($skus) > 0 && !$hasHealthySku) {
                $unavailableSkus[] = $productId;
            }
        }

        $issues = [];
        if ($failedAudit) {
            $issues[] = $this->issue(
                'P0',
                'catalog',
                'catalog.audit_failed',
                count($failedAudit) . ' 个商品未通过平台审核，当前无法正常销售。',
                '按 Product API 返回的审核原因逐个修正类目、属性、素材或合规声明，再重新提交人工确认。',
                '这些商品的最新审核状态全部为通过，且报告保留复核时间与商品 ID。',
                ['product_ids' => array_slice($failedAudit, 0, 20), 'count' => count($failedAudit)]
            );
        }
        if ($incomplete) {
            $issues[] = $this->issue(
                'P1',
                'catalog',
                'catalog.incomplete_product',
                count($incomplete) . ' 个商品缺少标题、类目、主图或 SKU 等上架必要信息。',
                '先补齐缺失字段，再检查标题与图片是否真实对应目标 SKU；本工具只生成修改清单，不写回店铺。',
                '抽检商品具备完整标题、类目、至少一张主图和至少一个可售 SKU。',
                ['product_ids' => array_slice($incomplete, 0, 20), 'count' => count($incomplete)]
            );
        }
        if ($unavailableSkus) {
            $issues[] = $this->issue(
                'P1',
                'health',
                'health.unavailable_skus',
                count($unavailableSkus) . ' 个商品没有同时满足有效价格和可售库存的 SKU。',
                '核对价格、币种、仓库库存和停售状态；确认后再由运营人员在 Seller Center 修改。',
                '每个目标商品至少有一个价格大于零、库存大于零且状态可售的 SKU。',
                ['product_ids' => array_slice($unavailableSkus, 0, 20), 'count' => count($unavailableSkus)]
            );
        }

        return $issues;
    }

    private function fulfillmentIssues(array $orders, bool $provided, DateTimeImmutable $asOf): array
    {
        if (!$provided) {
            return [$this->issue(
                'P1',
                'health',
                'health.missing_order_data',
                '没有提供全量订单，无法判断取消率、履约积压或退款率。',
                '导入同一时间窗内的全部订单，而不是只导入问题订单。',
                '订单数据包含清晰的时间窗、订单 ID、状态、创建时间和更新时间。',
                ['orders' => 0]
            )];
        }

        $cancelled = [];
        $stale = [];
        $terminal = ['CANCEL', 'CANCELED', 'CANCELLED', 'COMPLETED', 'DELIVERED', 'CLOSED'];

        foreach ($orders as $index => $order) {
            $orderId = (string) ($this->firstValue($order, ['id', 'order_id']) ?? 'row-' . ($index + 1));
            $status = $this->status($order);
            if (in_array($status, ['CANCEL', 'CANCELED', 'CANCELLED'], true)) {
                $cancelled[] = $orderId;
            }

            $updatedAt = $this->timestamp($this->firstValue($order, ['update_time', 'updated_at', 'create_time', 'created_at']));
            if (!in_array($status, $terminal, true)
                && $updatedAt !== null
                && ($asOf->getTimestamp() - $updatedAt) > 7 * 86400) {
                $stale[] = $orderId;
            }
        }

        $issues = [];
        $rate = count($orders) > 0 ? count($cancelled) / count($orders) : 0.0;
        if ($rate > 0.05) {
            $issues[] = $this->issue(
                $rate > 0.10 ? 'P1' : 'P2',
                'fulfillment',
                'fulfillment.high_cancellation_rate',
                '当前时间窗取消订单占比为 ' . $this->percent($rate) . '。',
                '按取消原因、SKU、渠道和履约节点拆分，优先处理库存不同步、超时发货和价格异常。',
                '连续两个同口径周期取消率不高于 5%，且主要原因都有负责人和完成记录。',
                ['cancelled_orders' => count($cancelled), 'orders' => count($orders), 'order_ids' => array_slice($cancelled, 0, 20)]
            );
        }
        if ($stale) {
            $issues[] = $this->issue(
                'P1',
                'fulfillment',
                'fulfillment.stale_open_orders',
                count($stale) . ' 个未完成订单超过 7 天没有状态更新。',
                '逐单核对仓库、物流和平台异常，先处理最老订单；不要由审计工具自动取消或退款。',
                '所有列出的订单已更新为有效物流状态或由运营留下人工处置记录。',
                ['order_ids' => array_slice($stale, 0, 20), 'count' => count($stale)]
            );
        }

        return $issues;
    }

    private function refundIssues(
        array $returns,
        array $finance,
        ?float $refundOrderRate,
        bool $ordersProvided,
        bool $financeProvided
    ): array {
        $issues = [];

        if ($ordersProvided && $refundOrderRate !== null && $refundOrderRate > 0.05) {
            $issues[] = $this->issue(
                $refundOrderRate > 0.10 ? 'P1' : 'P2',
                'refund',
                'refund.high_order_rate',
                '退款订单占全部订单的 ' . $this->percent($refundOrderRate) . '。',
                '按退款原因、SKU、渠道和下单月份做 Pareto，先修复贡献退款金额最高的商品或流程。',
                '连续两个同口径周期退款订单率不高于 5%，且报告保留全量订单分母。',
                ['refund_order_rate' => round($refundOrderRate, 4)]
            );
        }

        $reasonCounts = [];
        foreach ($returns as $return) {
            $reason = strtoupper(trim((string) ($this->firstValue(
                $return,
                ['reason', 'return_reason', 'reason_text', 'return_reason_text']
            ) ?? 'UNSPECIFIED')));
            $key = $reason === '' ? 'UNSPECIFIED' : $reason;
            $reasonCounts[$key] = ($reasonCounts[$key] ?? 0) + 1;
        }
        arsort($reasonCounts);
        $topReason = array_key_first($reasonCounts);
        $topCount = $topReason === null ? 0 : $reasonCounts[$topReason];
        if (count($returns) >= 2 && $topCount / count($returns) >= 0.5) {
            $issues[] = $this->issue(
                'P2',
                'refund',
                'refund.dominant_reason',
                '退款原因 “' . $topReason . '” 占当前退款记录的 ' . $this->percent($topCount / count($returns)) . '。',
                '回查对应 SKU、商品承诺、包装和履约证据，区分商品问题与物流问题。',
                '该原因对应的前三个根因均有修复动作，并在下一周期验证占比变化。',
                ['reason' => $topReason, 'count' => $topCount, 'returns' => count($returns)]
            );
        }

        $refundAmount = $this->sumRefundAmounts($returns);
        $financeRefundAmount = 0.0;
        foreach ($finance as $row) {
            $type = strtoupper((string) ($this->firstValue($row, ['type', 'transaction_type', 'event_type']) ?? ''));
            if (str_contains($type, 'REFUND')) {
                $financeRefundAmount += abs($this->amount($this->firstValue($row, ['amount', 'total_amount', 'payment_amount'])));
            }
        }

        if ($financeProvided && $refundAmount > 0
            && abs($refundAmount - $financeRefundAmount) / $refundAmount > 0.02) {
            $issues[] = $this->issue(
                'P1',
                'refund',
                'refund.finance_mismatch',
                '退货退款记录与财务退款流水相差 ' . number_format(abs($refundAmount - $financeRefundAmount), 2, '.', '') . '。',
                '按订单 ID、退款 ID、币种和结算时间逐笔对账，确认是否存在部分退款、跨期入账或漏数。',
                '两侧同币种退款金额差异不超过 2%，无法匹配的记录均有原因说明。',
                [
                    'return_refund_amount' => round($refundAmount, 2),
                    'finance_refund_amount' => round($financeRefundAmount, 2),
                ]
            );
        }

        return $issues;
    }

    private function issue(
        string $priority,
        string $dimension,
        string $code,
        string $happened,
        string $action,
        string $doneWhen,
        array $evidence
    ): array {
        return [
            'priority' => $priority,
            'dimension' => $dimension,
            'code' => $code,
            '发生什么' => $happened,
            '怎么改' => $action,
            '什么算完成' => $doneWhen,
            'evidence' => $evidence,
        ];
    }

    private function dimensionSummary(array $issues, array $available): array
    {
        $summary = [];
        foreach ($available as $dimension => $isAvailable) {
            $count = count(array_filter($issues, fn(array $issue): bool => $issue['dimension'] === $dimension));
            $summary[$dimension] = [
                'status' => !$isAvailable ? 'unavailable' : ($count > 0 ? 'attention' : 'clear'),
                'issue_count' => $count,
            ];
        }

        return $summary;
    }

    private function normalizeCollection(mixed $value, array $paths): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (array_is_list($value)) {
            return array_values(array_filter($value, 'is_array'));
        }

        foreach ($paths as $path) {
            $candidate = $this->path($value, $path, $found);
            if ($found && is_array($candidate)) {
                return array_is_list($candidate)
                    ? array_values(array_filter($candidate, 'is_array'))
                    : [$candidate];
            }
        }

        return isset($value['id']) ? [$value] : [];
    }

    private function firstValue(array $row, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = $this->path($row, $path, $found);
            if ($found && $value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function path(array $row, string $path, ?bool &$found = null): mixed
    {
        $current = $row;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                $found = false;
                return null;
            }
            $current = $current[$segment];
        }
        $found = true;

        return $current;
    }

    private function inventoryQuantity(array $sku): float
    {
        $inventory = $this->firstValue($sku, ['inventory', 'stock', 'available_quantity']);
        if (is_numeric($inventory)) {
            return (float) $inventory;
        }
        if (!is_array($inventory)) {
            return 0.0;
        }

        $rows = array_is_list($inventory) ? $inventory : [$inventory];
        $total = 0.0;
        foreach ($rows as $row) {
            $total += is_array($row)
                ? $this->amount($this->firstValue($row, ['quantity', 'available_quantity', 'stock']))
                : $this->amount($row);
        }

        return $total;
    }

    private function amount(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_array($value)) {
            foreach (['amount', 'value', 'total_amount'] as $key) {
                if (isset($value[$key]) && is_numeric($value[$key])) {
                    return (float) $value[$key];
                }
            }
        }

        return 0.0;
    }

    private function sumRefundAmounts(array $returns): float
    {
        $total = 0.0;
        foreach ($returns as $return) {
            $total += abs($this->amount($this->firstValue(
                $return,
                ['refund_amount', 'total_refund_amount', 'amount']
            )));
        }

        return $total;
    }

    private function status(array $row): string
    {
        return strtoupper((string) ($this->firstValue($row, ['status', 'order_status']) ?? ''));
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $timestamp = (int) $value;
            return $timestamp > 9999999999 ? (int) floor($timestamp / 1000) : $timestamp;
        }
        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);
            return $timestamp === false ? null : $timestamp;
        }

        return null;
    }

    private function asOf(mixed $value): DateTimeImmutable
    {
        if (is_string($value) && trim($value) !== '') {
            return new DateTimeImmutable($value);
        }

        return new DateTimeImmutable('now');
    }

    private function percent(float $value): string
    {
        return number_format($value * 100, 1, '.', '') . '%';
    }
}
