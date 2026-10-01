"""Build the NU Honours 2nd Year routine video: slides + Bangla voiceover -> MP4.
TTS: tries Microsoft neural Bangla (edge-tts) first, falls back to espeak-ng (robotic draft)."""
import asyncio, subprocess, os, sys
from PIL import Image, ImageDraw, ImageFont, ImageFilter

FONT = "/usr/share/fonts/truetype/noto/NotoSansBengali-Bold.ttf"
FONT_R = "/usr/share/fonts/truetype/noto/NotoSansBengali-Regular.ttf"
if not os.path.exists(FONT):
    FONT = FONT_R = "/usr/share/fonts/truetype/noto/NotoSerifBengali-Bold.ttf"
VOICE = os.environ.get("BN_VOICE", "bn-BD-NabanitaNeural")  # or bn-BD-PradeepNeural
W, H = 1280, 720

# (headline, [on-screen lines], voiceover)
SEGMENTS = [
 ("অনার্স ২য় বর্ষ", ["জাতীয় বিশ্ববিদ্যালয়", "পরীক্ষা ২০২৬-এর রুটিন প্রকাশিত"],
  "অনার্স ২য় বর্ষের শিক্ষার্থীদের জন্য এসেছে গুরুত্বপূর্ণ আপডেট! জাতীয় বিশ্ববিদ্যালয়ের অনার্স ২য় বর্ষ পরীক্ষা ২০২৬-এর রুটিন প্রকাশিত হয়েছে।"),
 ("কাদের জন্য প্রযোজ্য?", ["২০২৩–২৪ শিক্ষাবর্ষের নিয়মিত শিক্ষার্থী", "নির্দিষ্ট শিক্ষাবর্ষের অনিয়মিত ও উন্নয়ন পরীক্ষার্থী"],
  "এই রুটিনটি ২০২৩–২৪ শিক্ষাবর্ষের নিয়মিত শিক্ষার্থী এবং নির্দিষ্ট কয়েকটি শিক্ষাবর্ষের অনিয়মিত ও উন্নয়ন পরীক্ষার্থীদের জন্য প্রযোজ্য।"),
 ("পরীক্ষার সময়সূচি", ["শুরু: ২৮ সেপ্টেম্বর ২০২৬", "শেষ: ২৩ নভেম্বর ২০২৬"],
  "পরীক্ষা শুরু হবে ২৮ সেপ্টেম্বর ২০২৬ থেকে। আর পরীক্ষা শেষ হবে ২৩ নভেম্বর ২০২৬। অর্থাৎ, দীর্ঘ সময় ধরে চলবে অনার্স ২য় বর্ষের এই পরীক্ষা।"),
 ("কারা অংশ নিতে পারবেন?", ["নিয়মিত শিক্ষার্থী", "উন্নয়ন পরীক্ষার্থী: ২০২২–২৩, ২০২১–২২, ২০২০–২১", "শর্ত পূরণকারী অনিয়মিত শিক্ষার্থী"],
  "এবারের পরীক্ষায় নিয়মিত শিক্ষার্থীদের পাশাপাশি ২০২২–২৩, ২০২১–২২ এবং ২০২০–২১ শিক্ষাবর্ষের উন্নয়ন পরীক্ষার্থীরাও অংশ নিতে পারবেন। এছাড়া নির্দিষ্ট শর্ত পূরণকারী অনিয়মিত শিক্ষার্থীদেরও পরীক্ষায় অংশগ্রহণের সুযোগ রয়েছে।"),
 ("গুরুত্বপূর্ণ নির্দেশনা", ["পরীক্ষা শুরুর ৩০–৬০ মিনিট আগে আসন গ্রহণ", "অ্যাডমিট কার্ড নিজ নিজ বিভাগ থেকে সংগ্রহ"],
  "পরীক্ষার্থীদের জন্য গুরুত্বপূর্ণ নির্দেশনা। পরীক্ষা শুরুর অন্তত ৩০ থেকে ৬০ মিনিট আগে পরীক্ষার হলে আসন গ্রহণ করতে হবে। অ্যাডমিট কার্ড অবশ্যই নিজ নিজ বিভাগ থেকে সংগ্রহ করতে হবে।"),
 ("উত্তরপত্রে সতর্কতা", ["রোল নম্বর", "রেজিস্ট্রেশন নম্বর", "বিষয় কোড", "পরীক্ষার হলে মোবাইল ফোন নিষিদ্ধ"],
  "উত্তরপত্রে রোল নম্বর, রেজিস্ট্রেশন নম্বর এবং বিষয় কোড সঠিকভাবে পূরণ করতে হবে। আর সবচেয়ে গুরুত্বপূর্ণ বিষয়, পরীক্ষার হলে মোবাইল ফোন ব্যবহার করা যাবে না।"),
 ("প্রস্তুতি শুরু করুন", ["রুটিন অনুযায়ী এখনই প্রস্তুতি নিন"],
  "তাই যারা অনার্স ২য় বর্ষের পরীক্ষার্থী, এখন থেকেই রুটিন অনুযায়ী প্রস্তুতি শুরু করে দিন।"),
 ("সম্পূর্ণ রুটিন", ["লিংক ভিডিওর ডেসক্রিপশনে দেওয়া আছে", "educationbd.org"],
  "সম্পূর্ণ রুটিন এবং বিস্তারিত তথ্য দেখতে আপনারা EducationBD-এর দেওয়া লিংকে প্রবেশ করুন। লিংকটি ভিডিওর ডেসক্রিপশনে দেওয়া আছে। সব পরীক্ষার্থীর জন্য রইল শুভকামনা।"),
 ("EducationBD", ["জাতীয় বিশ্ববিদ্যালয়ের সকল আপডেট সবার আগে পেতে", "আমাদের সঙ্গে থাকুন"],
  "EducationBD-এর সঙ্গে থাকুন, জাতীয় বিশ্ববিদ্যালয়ের সকল আপডেট সবার আগে পেতে।"),
]

def tts(text, out):
    try:
        import edge_tts
        asyncio.run(edge_tts.Communicate(text, VOICE, rate="-5%").save(out))
        return "edge-tts"
    except Exception as e:
        wav = out.replace(".mp3", ".wav")
        subprocess.run(["espeak-ng", "-v", "bn", "-s", "135", "-w", wav, text], check=True)
        subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", wav, out], check=True)
        return "espeak-ng (fallback)"

import re
LATIN = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
if not os.path.exists(LATIN):
    LATIN = "/usr/share/fonts/truetype/freefont/FreeSansBold.ttf"

def mixed(d, xy, text, bn_font, size, fill):
    """Draw text, using a Latin font for ASCII runs (the Bengali font lacks them)."""
    x, y = xy
    lf = ImageFont.truetype(LATIN, int(size * 0.85))
    for run in re.findall(r"[A-Za-z.]+|[^A-Za-z.]+", text):
        f = lf if re.match(r"[A-Za-z]", run) else bn_font
        d.text((x, y), run, font=f, fill=fill)
        x += d.textlength(run, font=f)

def slide(i, head, lines, out):
    img = Image.new("RGB", (W, H))
    d = ImageDraw.Draw(img)
    for y in range(H):  # vertical gradient
        t = y / H
        d.line([(0, y), (W, y)], fill=(int(10 + 15 * t), int(40 + 40 * t), int(90 + 50 * t)))
    d.rectangle([0, 0, 24, H], fill=(255, 190, 40))
    fh, fl, fs = (ImageFont.truetype(FONT, 78), ImageFont.truetype(FONT_R, 46), ImageFont.truetype(FONT_R, 28))
    mixed(d, (90, 90), head, fh, 78, "white")
    d.line([(90, 200), (520, 200)], fill=(255, 190, 40), width=6)
    y = 250
    for ln in lines:
        d.ellipse([90, y + 24, 108, y + 42], fill=(255, 190, 40))
        mixed(d, (130, y), ln, fl, 46, (230, 240, 255)); y += 85
    mixed(d, (90, H - 70), "EducationBD  |  জাতীয় বিশ্ববিদ্যালয় অনার্স ২য় বর্ষ রুটিন ২০২৬", fs, 28, (170, 195, 230))
    img.save(out)

os.makedirs("work", exist_ok=True)
parts = []
engine = ""
for i, (head, lines, vo) in enumerate(SEGMENTS):
    png, mp3, mp4 = f"work/s{i}.png", f"work/s{i}.mp3", f"work/s{i}.mp4"
    slide(i, head, lines, png)
    engine = tts(vo, mp3)
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-loop", "1", "-i", png, "-i", mp3,
                    "-af", "apad=pad_dur=0.6", "-c:v", "libx264", "-tune", "stillimage", "-pix_fmt", "yuv420p",
                    "-r", "25", "-c:a", "aac", "-b:a", "160k", "-shortest", mp4], check=True)
    parts.append(mp4)
open("work/list.txt", "w").write("".join(f"file '{os.path.basename(p)}'\n" for p in parts))
subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-f", "concat", "-safe", "0", "-i", "work/list.txt",
                "-c", "copy", "honours-2nd-year-routine.mp4"], check=True)
print("voice engine:", engine)
