<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $jobTitle }}</title>
</head>
<body style="margin:0;background:#f8faff;font-family:Arial,sans-serif;color:#1a2b48;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:32px 16px;background:#f8faff;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 20px 35px -12px rgba(0,0,0,.08);">
                <tr>
                    <td style="height:8px;background:linear-gradient(90deg,#00d1c1,#4a90e2,#8a4fff);"></td>
                </tr>
                <tr>
                    <td style="padding:36px;">
                        <h1 style="margin:0 0 18px;font-size:26px;color:#1a2b48;">
                            {{ $locale === 'ar' ? 'دعوة لمقابلة وظيفية' : 'Job interview invitation' }}
                        </h1>

                        <p style="font-size:16px;line-height:1.8;color:#5a6e8a;">
                            {{ $locale === 'ar' ? 'مرحبًا' : 'Hello' }} {{ $candidateName }},
                        </p>

                        <p style="font-size:16px;line-height:1.8;color:#5a6e8a;">
                            @if($locale === 'ar')
                                تدعوك شركة <strong>{{ $companyName }}</strong> لإجراء مقابلة لوظيفة <strong>{{ $jobTitle }}</strong> عبر منصة Nervu.AI.
                            @else
                                <strong>{{ $companyName }}</strong> invited you to complete an interview for <strong>{{ $jobTitle }}</strong> through Nervu.AI.
                            @endif
                        </p>

                        @if(!empty($skills))
                            <p style="font-size:14px;color:#5a6e8a;">
                                <strong>{{ $locale === 'ar' ? 'المهارات:' : 'Skills:' }}</strong>
                                {{ implode(', ', $skills) }}
                            </p>
                        @endif

                        @if($identityRequired)
                            <p style="padding:14px 16px;border:1px solid #c6dcf3;border-radius:12px;background:#edf5fd;color:#2f6fae;font-size:14px;line-height:1.7;">
                                {{ $locale === 'ar'
                                    ? 'قبل بدء المقابلة سيُطلب منك رفع وثيقة هوية، التقاط صورة مباشرة، وإكمال اختبار حضور بسيط. ستُحذف الأدلة بعد المراجعة اليدوية.'
                                    : 'Before the interview, you will upload an identity document, capture a live selfie, and complete a liveness check. Evidence is deleted after manual review.' }}
                            </p>
                        @endif

                        <p style="margin:28px 0;text-align:center;">
                            <a href="{{ $invitationLink }}" style="display:inline-block;padding:14px 28px;border-radius:12px;background:#00a99d;color:#ffffff;text-decoration:none;font-weight:bold;">
                                {{ $locale === 'ar' ? 'فتح دعوة المقابلة' : 'Open interview invitation' }}
                            </a>
                        </p>

                        <p style="font-size:13px;line-height:1.7;color:#8a9bb0;">
                            {{ $locale === 'ar' ? 'تنتهي صلاحية هذه الدعوة في:' : 'This invitation expires at:' }}
                            {{ optional($expiresAt)->format('Y-m-d H:i') }}
                        </p>

                        <p style="font-size:13px;line-height:1.7;color:#8a9bb0;">
                            {{ $locale === 'ar'
                                ? 'الرابط مخصص لك ويُستخدم لتأكيد الوصول لأول مرة. لا تشاركه مع أي شخص.'
                                : 'This link is personal and is used to claim access once. Do not share it.' }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
