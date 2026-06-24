<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دعوة لمقابلة وظيفية</title>
    <style>
        body {
            font-family: 'Tahoma', 'Segoe UI', sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .content {
            padding: 30px;
        }
        .job-title {
            color: #667eea;
            font-size: 22px;
            font-weight: bold;
            margin: 10px 0;
        }
        .company-name {
            color: #666;
            font-size: 16px;
            margin-bottom: 20px;
        }
        .skills {
            background: #f0f0f0;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .skills span {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            margin: 5px;
            font-size: 12px;
        }
        .btn {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 14px 35px;
            text-decoration: none;
            border-radius: 50px;
            font-weight: bold;
            margin: 20px 0;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #5a67d8;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #999;
        }
        .note {
            background: #fff3cd;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✨ دعوة لمقابلة وظيفية ✨</h1>
        </div>

        <div class="content">
            <p>مرحباً <strong>{{ $candidateName }}</strong>،</p>

            <p>يسعدنا أن نبلغك بأن شركة <strong>{{ $companyName }}</strong> قد أبدت اهتمامها بملفك المهني ودعتك لإجراء مقابلة وظيفية عبر منصتنا.</p>

            <div class="job-title">{{ $jobTitle }}</div>
            <div class="company-name">{{ $companyName }}</div>

            @if(!empty($skills))
            <div class="skills">
                <strong>🎯 المهارات المطلوبة:</strong><br>
                @foreach($skills as $skill)
                    <span>{{ $skill }}</span>
                @endforeach
            </div>
            @endif

            <div style="text-align: center;">
                <a href="{{ $invitationLink }}" class="btn">🚀 ابدأ المقابلة الآن</a>
            </div>

            <div class="note">
                ⚠️ <strong>ملاحظة مهمة:</strong> هذا الرابط صالح فقط لهذه الدعوة. إذا لم تكن مسجلاً على المنصة، سيُطلب منك التسجيل قبل بدء المقابلة.
            </div>

            <p>المقابلة ستكون عبر المنصة مباشرة، وسيتم تقييم إجاباتك بواسطة الذكاء الاصطناعي. ستتلقى الشركة تقريراً كاملاً بنتائجك.</p>

            <p>مع تمنياتنا لك بالتوفيق! 🍀</p>
        </div>

        <div class="footer">
            هذه رسالة آلية، الرجاء عدم الرد عليها.<br>
            © {{ date('Y') }} منصة التدريب على المقابلات
        </div>
    </div>
</body>
</html>
