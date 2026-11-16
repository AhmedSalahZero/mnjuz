<?php

namespace App\Services\Email;

/** عنوان SMTP مرفوض: داخليّ أو محجوز أو لا يُحَلّ. رسالته تُعرض للتاجر. */
class SmtpHostNotAllowed extends \RuntimeException
{
}
