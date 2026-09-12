<?php

namespace App\Services;

use RuntimeException;

class VoucherAccounting
{
    public function totals(array $voucher, array $expenses): array
    {
        $cash = array_sum(array_column(array_filter($expenses, fn ($e) => $e['paymentMethod'] === 'cash'), 'amountMinor'));
        $bank = array_sum(array_column(array_filter($expenses, fn ($e) => $e['paymentMethod'] === 'bankTransfer'), 'amountMinor'));
        $expected = $voucher['openingFloatMinor'] + $voucher['cashSalesMinor'] - $cash;

        return [
            'cashExpensesMinor' => $cash,
            'bankExpensesMinor' => $bank,
            'totalExpensesMinor' => $cash + $bank,
            'totalSalesMinor' => $voucher['cashSalesMinor'] + $voucher['cardSalesMinor'],
            'expectedCashMinor' => $expected,
            'varianceMinor' => $voucher['countedCashMinor'] - $expected,
        ];
    }

    public function journals(array $voucher, array $expenses, array $categories): array
    {
        $totals = $this->totals($voucher, $expenses);
        $sales = [];
        if ($voucher['cashSalesMinor']) {
            $sales[] = $this->line('Cash', $voucher['cashSalesMinor'], 0);
        }
        if ($voucher['cardSalesMinor']) {
            $sales[] = $this->line('Bank', $voucher['cardSalesMinor'], 0);
        }
        if ($totals['totalSalesMinor']) {
            $sales[] = $this->line('Sales Revenue', 0, $totals['totalSalesMinor']);
        }
        $grouped = [];
        foreach ($expenses as $expense) {
            $grouped[$expense['categoryId']] = ($grouped[$expense['categoryId']] ?? 0) + $expense['amountMinor'];
        }
        $expenseLines = [];
        foreach ($grouped as $categoryId => $amount) {
            if (! isset($categories[$categoryId])) {
                throw new RuntimeException("Missing account mapping for category {$categoryId}");
            }
            $category = $categories[$categoryId];
            $expenseLines[] = $this->line($category['account_name'], $amount, 0, $category['account_code']);
        }
        if ($totals['varianceMinor'] < 0) {
            $expenseLines[] = $this->line('Cash Short / Over', abs($totals['varianceMinor']), 0);
        }
        if ($totals['varianceMinor'] > 0) {
            $expenseLines[] = $this->line('Cash', $totals['varianceMinor'], 0);
        }
        $cashCredit = $totals['cashExpensesMinor'] + max(-$totals['varianceMinor'], 0);
        if ($cashCredit) {
            $expenseLines[] = $this->line('Cash', 0, $cashCredit);
        }
        if ($totals['bankExpensesMinor']) {
            $expenseLines[] = $this->line('Bank', 0, $totals['bankExpensesMinor']);
        }
        if ($totals['varianceMinor'] > 0) {
            $expenseLines[] = $this->line('Cash Short / Over', 0, $totals['varianceMinor']);
        }

        return [$this->journal('sales', $sales), $this->journal('expenses', $expenseLines)];
    }

    private function line(string $name, int $debit, int $credit, ?string $code = null): array
    {
        return ['accountCode' => $code, 'accountName' => $name, 'debitMinor' => $debit, 'creditMinor' => $credit];
    }

    private function journal(string $type, array $lines): array
    {
        $debit = array_sum(array_column($lines, 'debitMinor'));
        $credit = array_sum(array_column($lines, 'creditMinor'));

        return ['type' => $type, 'lines' => $lines, 'debitTotalMinor' => $debit, 'creditTotalMinor' => $credit, 'balanced' => $debit === $credit];
    }
}
