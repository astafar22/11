EducationBD NU Result v1.3.0

Native UI only. No iframe and no direct browser request to NU captcha image.
The WordPress backend fetches the NU captcha using the same server-side session/cookies and embeds the image as a data URI so it displays inside the EducationBD form.

Shortcode:
[educationbd_nu_result]

Important: NU human verification is not bypassed; the visitor must enter the displayed verification code manually.
