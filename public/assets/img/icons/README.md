# Icon Assets

Binary assets are not stored in this repository. Generate the aviso watermark image
by running the helper script:

```
python tools/generate_aviso_watermark_gray.py
```

The script will create `public/assets/img/icons/aviso-watermark-gray.png` using
Pillow. You can pass a custom output path if you would like to inspect the file
before copying it into the repository:

```
python tools/generate_aviso_watermark_gray.py /tmp/aviso-watermark.png
```

To enable the aviso PDF background, place the provided design file at
`public/assets/img/fondo_pdf.png`. The PDF generator will scale the image to cover
each page, so using the original resolution is recommended.
