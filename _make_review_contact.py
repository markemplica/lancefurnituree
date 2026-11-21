from zipfile import ZipFile
from pathlib import Path
p=Path(r'C:\Users\User\Downloads\FurniCraft_Chapters_4_and_5_Draft.docx')
out=Path(r'C:\ashproject\furnicraft-system\_review_media')
out.mkdir(exist_ok=True)
with ZipFile(p) as z:
    names=[n for n in z.namelist() if n.startswith('word/media/')]
    for n in names:
        (out/Path(n).name).write_bytes(z.read(n))
print('Extracted images:',len(names))
try:
    from PIL import Image,ImageOps,ImageDraw
    files=[]
    for f in sorted(out.iterdir()):
        try:
            im=Image.open(f).convert('RGB')
            im.thumbnail((220,150))
            files.append((f.name,im.copy(),Image.open(f).size))
        except Exception: pass
    cols=4; cellw=250; cellh=190
    sheet=Image.new('RGB',(cols*cellw,((len(files)+cols-1)//cols)*cellh),'white')
    draw=ImageDraw.Draw(sheet)
    for i,(name,im,size) in enumerate(files):
        x=(i%cols)*cellw+10; y=(i//cols)*cellh+25
        sheet.paste(im,(x,y))
        draw.text((x,y-18),f'{name} {size}',fill='black')
    sheet.save(out/'contact_sheet.jpg',quality=90)
    print('Sheet',out/'contact_sheet.jpg','visual files',len(files))
except Exception as e: print('PIL issue',repr(e))
