<?php

if (! function_exists('generateNextInvoice')) {
    function generateNextInvoice($lastInvoice)
    {
        $prefix = substr($lastInvoice, 0, 1);
        $number = substr($lastInvoice, 1);

        $nextNumber = (int) $number + 1;

        return $prefix.str_pad($nextNumber, strlen($number), '0', STR_PAD_LEFT);
    }
}
