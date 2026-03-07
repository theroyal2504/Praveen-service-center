<?php
require_once 'config.php';

// Run at 11:59 PM every day
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// Get today's closing balance
$today_balance = mysqli_fetch_assoc(mysqli_query($conn, 
    "SELECT closing_balance FROM daily_balance WHERE balance_date = '$today'"));

$closing = $today_balance['closing_balance'] ?? 0;

// Create tomorrow's opening balance
$check = mysqli_query($conn, "SELECT id FROM daily_balance WHERE balance_date = '$tomorrow'");
if (mysqli_num_rows($check) == 0) {
    mysqli_query($conn, "INSERT INTO daily_balance (balance_date, opening_balance, closing_balance) 
                         VALUES ('$tomorrow', $closing, $closing)");
}

// Log the auto-update
file_put_contents('accounting_cron.log', date('Y-m-d H:i:s') . " - Daily balance carried forward: $closing\n", FILE_APPEND);