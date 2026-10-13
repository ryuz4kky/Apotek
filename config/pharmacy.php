<?php

return [
    'timezone' => env('PHARMACY_TIMEZONE', 'Asia/Makassar'),
    'modules' => ['dashboard' => 'view', 'products' => 'view', 'pos' => 'sales', 'suppliers' => 'supplier.view', 'purchases' => 'purchase.view', 'receipts' => 'receipt.view', 'stock' => 'inventory.view', 'ledger' => 'inventory.view', 'counts' => 'counts.view', 'prescriptions' => 'prescription.view', 'sales' => 'sales.view', 'shifts' => 'shift.view', 'locations' => 'inventory.view', 'temperatures' => 'temperature.view', 'finance' => 'finance.view', 'returns' => 'return.view', 'audit' => 'audit', 'users' => 'users'],
    'roles' => [
        'pemilik' => ['view', 'supplier.view', 'purchase.view', 'receipt.view', 'inventory.view', 'counts.view', 'temperature.view', 'sales.view', 'shift.view', 'finance.view', 'return.view', 'business.view', 'finance', 'cost', 'reports', 'audit', 'refund', 'discount', 'shift.approve'],
        'admin' => ['view', 'master', 'users', 'supplier.view', 'receipt.view', 'inventory.view', 'audit'],
        'apoteker_pj' => ['view', 'pharmacy', 'prepare', 'prescription.view', 'purchase.view', 'purchase.approve', 'supplier.view', 'receipt.view', 'receive.verify', 'inventory.view', 'counts.view', 'stock.manage', 'stock.approve', 'temperature.view', 'return.view', 'return.assess', 'sales.view', 'audit', 'reports', 'cost'],
        'apoteker' => ['view', 'pharmacy', 'prepare', 'prescription.view', 'purchase.view', 'supplier.view', 'receipt.view', 'receive.verify', 'inventory.view', 'counts.view', 'stock.manage', 'temperature.view', 'return.view', 'return.assess', 'sales.view', 'audit'],
        'ttk' => ['view', 'prescription.view', 'prepare', 'receipt.view', 'receive', 'supplier.view', 'purchase.view', 'inventory.view', 'counts.view', 'stock.manage', 'temperature.view'],
        'gudang' => ['view', 'master.supplier', 'supplier.view', 'purchase.view', 'purchase', 'receipt.view', 'receive', 'inventory.view', 'counts.view', 'stock.manage', 'temperature.view', 'cost'],
        'kasir' => ['view', 'sales', 'sales.view', 'shift', 'shift.view', 'return.view', 'return.intake'],
        'auditor' => ['view', 'supplier.view', 'purchase.view', 'receipt.view', 'inventory.view', 'counts.view', 'temperature.view', 'sales.view', 'shift.view', 'finance.view', 'return.view', 'business.view', 'cost', 'reports', 'audit'],
    ],
];
