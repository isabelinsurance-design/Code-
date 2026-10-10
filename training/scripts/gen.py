"""Render a dialogue script (JSON) to an MP3 with Kokoro TTS.

Usage: python -I gen.py MODEL_DIR SCRIPT.json OUT.mp3
"""
import json
import subprocess
import sys
import tempfile

import numpy as np
import soundfile as sf
from kokoro_onnx import Kokoro

model_dir, script_path, out_path = sys.argv[1:4]
kokoro = Kokoro(f"{model_dir}/kokoro-v1.0.onnx", f"{model_dir}/voices-v1.0.bin")

with open(script_path) as f:
    script = json.load(f)

voices = script["voices"]  # speaker -> {"voice": ..., "lang": ...}
pieces = []
rate = 24000
for line in script["lines"]:
    speaker, text = line["speaker"], line["text"]
    v = voices[speaker]
    samples, rate = kokoro.create(text, voice=v["voice"], speed=v.get("speed", 1.0), lang=v["lang"])
    pieces.append(samples)
    pause = line.get("pause", 0.9 if speaker == "Narrator" else 0.45)
    pieces.append(np.zeros(int(rate * pause), dtype=np.float32))

audio = np.concatenate(pieces)
with tempfile.NamedTemporaryFile(suffix=".wav") as wav:
    sf.write(wav.name, audio, rate)
    subprocess.run(
        ["ffmpeg", "-y", "-loglevel", "error", "-i", wav.name, "-ac", "1", "-b:a", "96k", out_path],
        check=True,
    )
print(f"{out_path}: {len(audio) / rate:.0f}s")
