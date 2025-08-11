<?php
function generateOTP(): string
{
    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    return $otp;
}
