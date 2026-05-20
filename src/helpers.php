<?php

function clean_input($value)
{
    return trim((string) $value);
}

function money_format_simple($amount, $currency = 'NPR')
{
    return $currency . ' ' . number_format((float) $amount, 2);
}

function generate_member_code_from_name($fullName, $id)
{
    $name = strtoupper(preg_replace('/\s+/', '', $fullName));
    $prefix = substr($name, 0, 3);

    if (strlen($prefix) < 3) {
        $prefix = str_pad($prefix, 3, 'X');
    }

    return $prefix . str_pad((string) $id, 8, '0', STR_PAD_LEFT);
}
