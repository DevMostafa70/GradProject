"""
Script to generate test audio files for development and testing.
Creates synthetic audio with varying speech characteristics.
"""

import numpy as np
import soundfile as sf
import os

def generate_sine_wave(frequency, duration, sample_rate=22050, amplitude=0.5):
    """Generate a simple sine wave."""
    t = np.linspace(0, duration, int(sample_rate * duration))
    return amplitude * np.sin(2 * np.pi * frequency * t)

def generate_test_audio(output_dir='test_audio'):
    """
    Generate various test audio files.

    Creates:
    1. Normal speech-like audio
    2. Silent audio
    3. Fast speech-like audio
    4. Audio with pauses
    """
    os.makedirs(output_dir, exist_ok=True)
    sr = 22050

    # Test 1: Normal speech simulation (varying frequencies)
    print("Generating normal speech audio...")
    audio = np.array([])
    frequencies = [150, 180, 200, 170, 190]  # Series of pitch changes
    durations = [0.5, 0.3, 0.4, 0.6, 0.4]   # Varying segment lengths

    for freq, dur in zip(frequencies, durations):
        segment = generate_sine_wave(freq, dur, sr)
        audio = np.concatenate([audio, segment])

    sf.write(f'{output_dir}/normal_speech.wav', audio, sr)

    # Test 2: Audio with long pauses
    print("Generating audio with pauses...")
    audio = np.array([])
    for i in range(5):
        # Speech segment
        speech = generate_sine_wave(180, 0.3, sr)
        audio = np.concatenate([audio, speech])

        # Pause (near silence)
        pause = generate_sine_wave(0, 0.5, sr, amplitude=0.001)
        audio = np.concatenate([audio, pause])

    sf.write(f'{output_dir}/with_pauses.wav', audio, sr)

    # Test 3: Fast tempo audio
    print("Generating fast tempo audio...")
    audio = np.array([])
    for i in range(10):
        segment = generate_sine_wave(200, 0.1, sr)
        audio = np.concatenate([audio, segment])

    sf.write(f'{output_dir}/fast_tempo.wav', audio, sr)

    # Test 4: Mostly silent
    print("Generating mostly silent audio...")
    silence = generate_sine_wave(0, 3, sr, amplitude=0.0005)
    speech = generate_sine_wave(180, 0.5, sr)
    audio = np.concatenate([silence, speech, silence])

    sf.write(f'{output_dir}/mostly_silent.wav', audio, sr)

    print(f"\nTest files generated in '{output_dir}/' directory:")
    print("  - normal_speech.wav")
    print("  - with_pauses.wav")
    print("  - fast_tempo.wav")
    print("  - mostly_silent.wav")
    print("\nUse these files with curl:")
    print("curl -X POST http://localhost:5001/analyze \\")
    print("  -F \"audio_file=@test_audio/normal_speech.wav\"")

if __name__ == '__main__':
    generate_test_audio()
