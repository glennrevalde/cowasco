<?php
declare(strict_types=1);

/**
 * Point of Sale > FB POS (v35): the POS Register with a food and beverage screen. The selling, the shifts, the payment
 * methods, the receipts and the reports are those of the POS Register (modules/pos/register.php); only the screen
 * differs: menu groups and pictures of the items with their price.
 */
$POS_MODE = 'fb';
require __DIR__ . '/register.php';
