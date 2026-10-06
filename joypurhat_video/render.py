import numpy as np, math, random, subprocess, sys
from PIL import Image, ImageDraw, ImageFont, ImageFilter
W,H=1280,720; BW,BH=1664,936; FPS=24; DUR=176.72
FONT='/usr/share/fonts/truetype/freefont/FreeSans.ttf'
def grad(top,bot,h=BH,w=BW):
    t=np.linspace(0,1,h)[:,None,None]; a=np.array(top)[None,None,:]; b=np.array(bot)[None,None,:]
    return Image.fromarray((a*(1-t)+b*t).repeat(w,1).astype(np.uint8))
def sky(kind):
    return {'day':grad((70,140,215),(200,230,250)),'gold':grad((250,170,90),(255,225,160)),
            'dusk':grad((60,40,110),(255,140,80)),'morn':grad((120,180,230),(255,235,200)),
            'dawn':grad((90,100,170),(255,190,140))}[kind]
def sun(d,x,y,r,col=(255,240,200)):
    for i in range(12,0,-1):
        k=i/12; c=tuple(int(col[j]*0.35+255*0.0+col[j]*0.0) for j in range(3))
    g=Image.new('RGBA',(BW,BH),(0,0,0,0)); gd=ImageDraw.Draw(g)
    for i in range(30,0,-1): gd.ellipse([x-r*i/6,y-r*i/6,x+r*i/6,y+r*i/6],fill=col+(int(6),))
    return g
def hills(d,y,amp,col,seed,step=8):
    random.seed(seed); ph=[random.random()*6 for _ in range(3)]
    pts=[(0,BH)]
    for x in range(0,BW+step,step):
        pts.append((x,y+amp*(math.sin(x/230+ph[0])+0.5*math.sin(x/90+ph[1])+0.3*math.sin(x/37+ph[2]))))
    pts.append((BW,BH)); d.polygon(pts,fill=col)
def field(d,y0,y1,c1,c2,rows=26):
    for i in range(rows):
        t=i/rows; ya=y0+(y1-y0)*t**1.4; yb=y0+(y1-y0)*((i+1)/rows)**1.4
        c=tuple(int(c1[k]*(1-t)+c2[k]*t+(6 if i%2 else -6)) for k in range(3)); d.rectangle([0,ya,BW,yb+1],fill=c)
def palm(d,x,y,h,lean=0,col=(40,90,45)):
    tx=x+lean;ty=y-h
    d.line([(x,y),(x+lean*.4,y-h*.55),(tx,ty)],fill=(95,70,50),width=max(3,int(h/28)))
    for a in range(-80,81,28):
        ang=math.radians(a-90); L=h*.45
        mx=tx+math.cos(ang)*L*.55; my=ty+math.sin(ang)*L*.55-L*.1
        ex=tx+math.cos(ang)*L; ey=ty+math.sin(ang)*L*.6+L*.35
        d.line([(tx,ty),(mx,my),(ex,ey)],fill=col,width=max(3,int(h/45)))
def tree(d,x,y,h,col=(35,95,45)):
    d.rectangle([x-h*.04,y-h*.5,x+h*.04,y],fill=(80,58,40))
    for dx,dy,r in [(0,-.62,.3),(-.22,-.5,.22),(.22,-.5,.22),(0,-.8,.2),(-.15,-.72,.2),(.15,-.72,.2)]:
        d.ellipse([x+dx*h-r*h,y+dy*h-r*h,x+dx*h+r*h,y+dy*h+r*h],fill=col)
def hut(d,x,y,w,h):
    d.rectangle([x,y-h*.55,x+w,y],fill=(150,115,80)); d.polygon([(x-w*.12,y-h*.55),(x+w/2,y-h),(x+w*1.12,y-h*.55)],fill=(190,150,70))
    d.rectangle([x+w*.4,y-h*.35,x+w*.6,y],fill=(70,45,30))
def river(d,y0,y1,c1,c2):
    for i in range(20):
        t=i/20; c=tuple(int(c1[k]*(1-t)+c2[k]*t) for k in range(3)); d.rectangle([0,y0+(y1-y0)*t,BW,y0+(y1-y0)*(t+.05)+1],fill=c)
def mosque(d,x,y,s,col=(235,230,215)):
    d.rectangle([x-s,y-s*.9,x+s,y],fill=col); d.pieslice([x-s*.6,y-s*1.7,x+s*.6,y-s*.1],180,360,fill=(205,200,185))
    for dx in(-s,s): d.rectangle([dx+x-s*.1,y-s*1.6,dx+x+s*.1,y],fill=col); d.pieslice([dx+x-s*.16,y-s*1.85,dx+x+s*.16,y-s*1.45],180,360,fill=(205,200,185))
def stripes(d,y0,y1,col):
    for y in range(int(y0),int(y1),9): d.line([(0,y),(BW,y)],fill=col,width=1)
def soft(im,r=1.2): return im.filter(ImageFilter.GaussianBlur(r))

SC={}
def scene(name):
    def f(fn): SC[name]=fn; return fn
    return f

@scene('map')
def s1():
    im=sky('morn'); d=ImageDraw.Draw(im); im.alpha_composite if False else None
    im=im.convert('RGBA'); im.alpha_composite(sun(d,1250,300,40,(255,230,170))); im=im.convert('RGB'); d=ImageDraw.Draw(im)
    hills(d,560,18,(150,185,150),1); hills(d,610,14,(110,165,110),2)
    field(d,640,BH,(120,185,80),(70,140,50)); 
    for x,y,h in [(160,660,230),(330,640,180),(1450,665,240),(1280,650,190),(1560,640,170)]: palm(d,x,y,h,lean=random.choice([-25,25]))
    hut(d,700,690,110,70); tree(d,900,680,150); tree(d,620,670,120)
    return soft(im,.8)
@scene('river')
def s2():
    im=sky('gold').convert('RGBA'); im.alpha_composite(sun(None,520,430,50,(255,220,140))); im=im.convert('RGB'); d=ImageDraw.Draw(im)
    hills(d,470,10,(110,140,100),3); field(d,490,590,(120,170,70),(90,150,60),8)
    river(d,590,BH,(250,190,120),(70,110,150))
    for x,y,h in [(90,600,250),(250,590,210),(1560,600,260),(1420,590,200)]: palm(d,x,y,h,lean=random.choice([-30,30]))
    tree(d,1250,590,160); tree(d,420,585,130)
    return soft(im,.8)
@scene('paddy')
def s3():
    im=sky('day').convert('RGBA'); im.alpha_composite(sun(None,300,170,34,(255,250,220))); im=im.convert('RGB'); d=ImageDraw.Draw(im)
    hills(d,430,12,(120,160,140),4); field(d,450,BH,(150,200,70),(60,130,40),30)
    for i,y in enumerate(range(480,BH,22)): 
        for x in range(-20,BW,34+i*2):
            d.line([(x,y),(x+3,y-6-i*.5)],fill=(40,110+i,35),width=2)
    for x,y,h in [(1500,470,220),(120,480,190),(800,465,140)]: palm(d,x,y,h,lean=random.choice([-20,20]))
    hut(d,1040,500,90,60); tree(d,1180,495,120)
    return soft(im,.7)
@scene('history')
def s4():
    im=sky('dawn').convert('RGBA'); im.alpha_composite(sun(None,830,500,60,(255,200,140))); im=im.convert('RGB'); d=ImageDraw.Draw(im)
    hills(d,520,12,(100,90,120),5); field(d,600,BH,(120,140,80),(80,100,60),12)
    # terraced brick temple ruins
    cx=830
    for i,(w,h) in enumerate([(520,70),(420,70),(320,70),(220,70),(130,60)]):
        y=640-i*70; d.rectangle([cx-w/2,y-h,cx+w/2,y],fill=(150-i*6,70-i*3,50-i*2)); 
        for k in range(int(w/26)): d.rectangle([cx-w/2+k*26+6,y-h+14,cx-w/2+k*26+16,y-h+46],fill=(110,50,38))
    d.polygon([(cx-45,640-350),(cx,640-430),(cx+45,640-350)],fill=(130,55,40))
    for x,y,h in [(250,640,200),(1500,640,220)]: palm(d,x,y,h,lean=random.choice([-25,25]),col=(30,70,50))
    tree(d,1250,650,150,(30,80,50)); tree(d,420,650,130,(30,80,50))
    return soft(im,.8)
@scene('mill')
def s5():
    im=sky('day').convert('RGBA'); im.alpha_composite(sun(None,1350,150,30)); im=im.convert('RGB'); d=ImageDraw.Draw(im)
    hills(d,480,10,(130,170,150),6); field(d,520,BH,(140,190,70),(70,130,50),20)
    # sugarcane rows
    for i,y in enumerate(range(700,BH,24)):
        for x in range(-10,BW,16): d.line([(x,y),(x-4,y-50-i*2)],fill=(70,140-i,50),width=3)
    d.rectangle([780,440,1180,640],fill=(190,180,170)); d.rectangle([780,430,1180,455],fill=(150,70,60))
    for x in (860,960,1060): d.rectangle([x,500,x+60,560],fill=(90,120,150))
    for x,h in [(820,300),(1100,360)]:
        d.polygon([(x-18,640),(x+18,640),(x+10,640-h),(x-10,640-h)],fill=(160,70,55)); d.rectangle([x-12,640-h-10,x+12,640-h],fill=(60,60,60))
    return soft(im,.8)
@scene('martyr')
def s6():
    im=sky('dusk').convert('RGBA'); im.alpha_composite(sun(None,830,560,70,(255,170,100))); im=im.convert('RGB'); d=ImageDraw.Draw(im)
    hills(d,590,10,(60,45,90),7); d.rectangle([0,640,BW,BH],fill=(45,60,50)); field(d,640,BH,(45,75,50),(30,50,40),10)
    cx=830
    d.rectangle([cx-260,650,cx+260,665],fill=(200,195,190)); d.rectangle([cx-200,640,cx+200,650],fill=(190,185,180))
    for dx,hh,tilt in [(-140,330,-18),(-70,400,-8),(0,470,0),(70,400,8),(140,330,18)]:
        d.polygon([(cx+dx-18,640),(cx+dx+18,640),(cx+dx+tilt+12,640-hh),(cx+dx+tilt-12,640-hh)],fill=(235,230,225))
    d.ellipse([cx-30,330,cx+30,390],fill=(200,30,50))
    # flag
    d.line([(160,300),(160,660)],fill=(230,230,230),width=6); d.rectangle([160,300,330,400],fill=(0,106,78)); d.ellipse([220,325,275,380],fill=(244,42,65))
    for x,y,h in [(1450,660,240),(1560,660,200)]: palm(d,x,y,h,lean=random.choice([-25,25]),col=(25,55,45))
    return soft(im,.8)
@scene('village')
def s7():
    im=sky('morn').convert('RGBA'); im.alpha_composite(sun(None,1100,200,36,(255,240,190))); im=im.convert('RGB'); d=ImageDraw.Draw(im)
    hills(d,500,10,(140,175,140),8); field(d,520,BH,(130,180,80),(95,150,60),14)
    # village market stalls
    for i,x in enumerate(range(380,1300,200)):
        d.rectangle([x,640,x+140,720],fill=(120,90,60)); 
        d.polygon([(x-15,640),(x+155,640),(x+130,590),(x+10,590)],fill=[(200,60,50),(240,180,50),(60,140,200),(220,110,40),(90,170,90)][i%5])
        for k in range(5): d.ellipse([x+12+k*24,670,x+32+k*24,690],fill=[(230,60,50),(250,200,40),(120,190,70)][ (k+i)%3])
    for x,y,h in [(120,640,260),(1560,650,250),(1470,640,180)]: palm(d,x,y,h,lean=random.choice([-25,25]))
    tree(d,300,630,150); tree(d,1360,600,140)
    return soft(im,.8)
@scene('sunset')
def s8():
    im=sky('dusk').convert('RGBA'); im.alpha_composite(sun(None,830,500,80,(255,190,110))); im=im.convert('RGB'); d=ImageDraw.Draw(im)
    hills(d,540,12,(70,50,90),9); river(d,560,BH,(240,140,100),(40,50,90))
    for x,y,h in [(70,600,330),(210,600,270),(1580,600,330),(1430,600,260)]: palm(d,x,y,h,lean=random.choice([-30,30]),col=(20,45,40))
    return soft(im,.8)

PLAN=[('map',0,17,'জয়পুরহাট','বাংলাদেশের উত্তরের এক জনপদ'),
 ('river',17,36,'নদীর তীরে','ছোট যমুনা ও তুলসী গঙ্গার দেশ'),
 ('paddy',36,58,'সবুজ প্রকৃতি','ধানের মাঠ আর গ্রামবাংলার ছবি'),
 ('history',58,80,'প্রাচীন ইতিহাস','বৃহত্তর বগুড়া ও বরেন্দ্রভূমির উত্তরাধিকার'),
 ('mill',80,98,'কৃষি ও শিল্প','আখের মাঠ আর চিনিকলের জেলা'),
 ('martyr',98,118,'শহীদদের স্মরণে','মুক্তিযুদ্ধের রক্তে ভেজা মাটি'),
 ('village',118,142,'গ্রামের জীবন','হাট-বাজার আর মানুষের কোলাহল'),
 ('sunset',142,DUR+1,'আমাদের জয়পুরহাট','গর্বের এক নাম')]
random.seed(1)
BG={k:SC[k]() for k,*_ in PLAN}
f1=ImageFont.truetype(FONT,64); f2=ImageFont.truetype(FONT,34)
clouds=[(random.random()*W,random.randint(40,230),random.uniform(.6,1.4),random.uniform(8,22)) for _ in range(7)]
birds=[(random.random()*W,random.randint(60,260),random.uniform(30,60),random.random()*6) for _ in range(6)]
def cloud(ov,x,y,s,a):
    d=ImageDraw.Draw(ov)
    for dx,dy,r in [(0,0,38),(40,-14,46),(88,0,40),(40,10,38),(-30,12,28),(120,12,28)]:
        d.ellipse([x+dx*s-r*s,y+dy*s-r*s,x+dx*s+r*s,y+dy*s+r*s],fill=(255,255,255,a//2))
cache={}
def frame(t):
    i=[n for n,(k,a,b,*_) in enumerate(PLAN) if a<=t<b][-1]; k,a,b,t1,t2=PLAN[i]
    u=(t-a)/(b-a); z=1.0+0.13*u; 
    cw,ch=W/z*BW/ W*(1664/1664),0
    cw=BW/(1.0+0.13*0+0.0)/1.0; cw=(BW/1.30)*(1.0/ (z/1.0))*1.0
    cw=1280*1.25/z; ch=cw*9/16
    px=(BW-cw)/2+ math.sin(i)*30*(u-.5)*2; py=(BH-ch)/2
    im=BG[k].resize((W,H),Image.BICUBIC,box=(px,py,px+cw,py+ch)).convert('RGBA')
    ov=Image.new('RGBA',(W,H),(0,0,0,0)); d=ImageDraw.Draw(ov)
    if k not in('martyr',):
        for cx,cy,s,sp in clouds: cloud(ov,(cx+t*sp)%(W+300)-150,cy,s,150 if k!='sunset' else 70)
    if k in('map','river','paddy','village','mill','history'):
        for bx,by,sp,ph in birds:
            x=(bx+t*sp*1.5)%(W+100)-50; y=by+10*math.sin(t*.8+ph); w=7*math.sin(t*9+ph)
            d.line([(x-14,y+w),(x,y),(x+14,y+w)],fill=(30,30,40,230),width=3)
    if k in('river','sunset'):
        for n in range(60):
            x=(n*97+ t*20*(1+n%3))%W; y=H*.72+ (n*53)%int(H*.27); s=2+(n%4)
            d.line([(x,y),(x+14+s*3,y)],fill=(255,255,255,int(70+60*math.sin(t*3+n))),width=2)
        bx=(t*35)%(W+300)-150; by=H*.7+4*math.sin(t*2)
        d.polygon([(bx,by),(bx+130,by),(bx+105,by+22),(bx+25,by+22)],fill=(70,45,30,255)); d.line([(bx+60,by),(bx+60,by-60)],fill=(60,40,30,255),width=3); d.polygon([(bx+60,by-58),(bx+60,by-8),(bx+105,by-8)],fill=(245,235,220,240))
    if k=='mill':
        for n in range(14):
            ph=(t*.35+n/14)%1; x=H and (1100*1.0+ (1280*0)+0); x=745+ph*60+8*math.sin(t+n); y=250-ph*230
            r=20+ph*40; d.ellipse([x-r,y-r,x+r,y+r],fill=(235,235,235,int(110*(1-ph))))
    if k=='martyr':
        for n in range(36):
            x=(n*173)%W; y=(n*97+t*(8+n%5))%H; r=2+(n%3); a_=int(90+90*math.sin(t*2+n)); d.ellipse([x-r,y-r,x+r,y+r],fill=(255,220,140,a_))
    ov=ov.filter(ImageFilter.GaussianBlur(1.2)); im.alpha_composite(ov)
    # vignette
    if 'vig' not in cache:
        yy,xx=np.mgrid[0:H,0:W]; r=np.sqrt(((xx-W/2)/(W/2))**2+((yy-H/2)/(H/2))**2); cache['vig']=np.clip(1-0.28*r**2,.55,1)[:,:,None]
    arr=(np.asarray(im.convert('RGB')).astype(np.float32)*cache['vig']).astype(np.uint8); im=Image.fromarray(arr).convert('RGBA')
    # captions
    tin=min(1,u*b*(b-a)/ (b-a) / 1.0) if False else min(1,(t-a)/1.0); tout=min(1,(b-t)/1.0); al=max(0,min(tin,tout))
    if al>0 and u<.75:
        cap=Image.new('RGBA',(W,H),(0,0,0,0)); cd=ImageDraw.Draw(cap)
        w1=cd.textlength(t1,font=f1); w2=cd.textlength(t2,font=f2); bw=max(w1,w2)+80; y0=H-190
        cd.rounded_rectangle([(W-bw)/2,y0,(W+bw)/2,y0+150],radius=22,fill=(0,0,0,int(120*al)))
        cd.text((W/2,y0+14),t1,font=f1,fill=(255,255,255,int(255*al)),anchor='mt'); cd.text((W/2,y0+96),t2,font=f2,fill=(255,230,170,int(255*al)),anchor='mt')
        im.alpha_composite(cap)
    # fade in/out
    fa=min(1,t/1.2,(DUR-t)/1.5)
    if fa<1: im=Image.blend(Image.new('RGBA',(W,H),(0,0,0,255)),im,max(0,fa))
    return im.convert('RGB')
if __name__=='__main__':
    if len(sys.argv)>1:
        for t in map(float,sys.argv[1:]): frame(t).save(f'prev_{t}.png')
        sys.exit()
    p=subprocess.Popen(['ffmpeg','-y','-loglevel','error','-f','rawvideo','-pix_fmt','rgb24','-s',f'{W}x{H}','-r',str(FPS),'-i','-',
      '-i',sys.argv[0] and __import__('glob').glob('/root/.claude/uploads/*/*.wav')[0],'-c:v','libx264','-preset','medium','-crf','21','-pix_fmt','yuv420p','-c:a','aac','-b:a','192k','-shortest','joypurhat.mp4'],stdin=subprocess.PIPE)
    for n in range(int(DUR*FPS)): p.stdin.write(frame(n/FPS).tobytes())
    p.stdin.close(); p.wait()
