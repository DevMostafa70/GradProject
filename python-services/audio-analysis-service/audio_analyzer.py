"""
Core audio analysis module using librosa.
Provides real-time analysis of audio files for speech assessment.
"""

import librosa
import numpy as np
from scipy import signal
from typing import Dict, Any, Tuple
import logging
import os
import tempfile
from pydub import AudioSegment
import warnings
from datetime import datetime, timezone

warnings.filterwarnings("ignore", category=UserWarning)
warnings.filterwarnings("ignore", category=FutureWarning)

logger = logging.getLogger(__name__)


class AudioAnalyzer:
    def __init__(self, config):
        self.config = config
        self.target_sr = config.TARGET_SAMPLE_RATE

    def load_audio(self, file_path: str) -> Tuple[np.ndarray, int]:
        logger.info(f"Loading audio file: {file_path}")

        if not os.path.exists(file_path):
            raise FileNotFoundError(f"Audio file not found: {file_path}")

        file_size_mb = os.path.getsize(file_path) / (1024 * 1024)
        if file_size_mb > self.config.MAX_FILE_SIZE_MB:
            raise ValueError(
                f"File too large: {file_size_mb:.2f}MB. "
                f"Maximum allowed: {self.config.MAX_FILE_SIZE_MB}MB"
            )

        try:
            audio, sr = librosa.load(file_path, sr=self.target_sr, mono=True)
            audio = np.nan_to_num(audio, nan=0.0, posinf=0.0, neginf=0.0)

            if np.max(np.abs(audio)) < 0.001:
                logger.warning("Audio file appears to be silent or very quiet")

            duration = librosa.get_duration(y=audio, sr=self.target_sr)

            logger.info(
                f"Audio loaded: {duration:.2f}s, "
                f"sample_rate: {self.target_sr}Hz, "
                f"max_amplitude: {np.max(np.abs(audio)):.4f}"
            )

            return audio, self.target_sr

        except Exception as e:
            logger.error(f"Failed to load audio: {str(e)}")

            try:
                logger.info("Attempting alternative loading with pydub...")

                audio_segment = AudioSegment.from_file(file_path)
                audio_segment = audio_segment.set_channels(1)
                audio_segment = audio_segment.set_frame_rate(self.target_sr)

                with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
                    audio_segment.export(tmp.name, format="wav")
                    audio, sr = librosa.load(tmp.name, sr=self.target_sr, mono=True)
                    os.unlink(tmp.name)

                audio = np.nan_to_num(audio, nan=0.0, posinf=0.0, neginf=0.0)

                logger.info("Alternative loading successful")
                return audio, self.target_sr

            except Exception as alt_error:
                logger.error(f"Alternative loading also failed: {str(alt_error)}")
                raise RuntimeError(
                    f"Cannot load audio file. Original error: {str(e)}. "
                    f"Alternative error: {str(alt_error)}"
                )

    def normalize_audio(self, audio: np.ndarray) -> np.ndarray:
        audio = audio - np.mean(audio)

        max_val = np.max(np.abs(audio))
        if max_val > 0:
            audio = audio / max_val * 0.95

        return audio

    def calculate_rms_energy(self, audio: np.ndarray) -> Dict[str, float]:
        frame_length = 2048
        hop_length = 512

        rms = librosa.feature.rms(
            y=audio,
            frame_length=frame_length,
            hop_length=hop_length
        )[0]

        rms_db = librosa.amplitude_to_db(rms, ref=np.max)

        energy_stats = {
            "rms_mean": float(np.mean(rms)),
            "rms_std": float(np.std(rms)),
            "rms_max": float(np.max(rms)),
            "rms_min": float(np.min(rms)),
            "energy_db_mean": float(np.mean(rms_db)),
            "energy_db_std": float(np.std(rms_db)),
            "energy_db_range": float(np.max(rms_db) - np.min(rms_db)),
        }

        energy_percentiles = np.percentile(rms, [25, 50, 75, 90])
        energy_stats["energy_percentile_25"] = float(energy_percentiles[0])
        energy_stats["energy_percentile_50"] = float(energy_percentiles[1])
        energy_stats["energy_percentile_75"] = float(energy_percentiles[2])
        energy_stats["energy_percentile_90"] = float(energy_percentiles[3])

        return energy_stats

    def calculate_pitch(self, audio: np.ndarray, sr: int) -> Dict[str, Any]:
        try:
            f0, voiced_flag, voiced_probs = librosa.pyin(
                audio,
                fmin=self.config.PITCH_FMIN,
                fmax=self.config.PITCH_FMAX,
                sr=sr,
                fill_na=None
            )

            if f0 is not None and voiced_flag is not None:
                valid_pitches = f0[voiced_flag]
            else:
                valid_pitches = np.array([])

            if len(valid_pitches) > 0:
                pitch_mean = float(np.mean(valid_pitches))
                pitch_std = float(np.std(valid_pitches))
                pitch_variability = pitch_std / pitch_mean if pitch_mean > 0 else 0

                return {
                    "pitch_mean": pitch_mean,
                    "pitch_median": float(np.median(valid_pitches)),
                    "pitch_std": pitch_std,
                    "pitch_min": float(np.min(valid_pitches)),
                    "pitch_max": float(np.max(valid_pitches)),
                    "voiced_frames": int(np.sum(voiced_flag)) if voiced_flag is not None else 0,
                    "total_frames": len(f0) if f0 is not None else 0,
                    "voicing_ratio": float(np.mean(voiced_flag)) if voiced_flag is not None else 0,
                    "pitch_range": float(np.max(valid_pitches) - np.min(valid_pitches)),
                    "pitch_variability": float(pitch_variability),
                    "pitch_stability": float(1.0 / (1.0 + pitch_variability * 10)),
                }

            logger.warning("No voiced frames detected for pitch analysis")

        except Exception as e:
            logger.error(f"Pitch calculation failed: {str(e)}")

        return {
            "pitch_mean": 0,
            "pitch_median": 0,
            "pitch_std": 0,
            "pitch_min": 0,
            "pitch_max": 0,
            "voiced_frames": 0,
            "total_frames": 0,
            "voicing_ratio": 0,
            "pitch_range": 0,
            "pitch_variability": 0,
            "pitch_stability": 0,
        }

    def calculate_tempo(self, audio: np.ndarray, sr: int) -> Dict[str, Any]:
        try:
            onset_env = librosa.onset.onset_strength(y=audio, sr=sr)

            tempo, beats = librosa.beat.beat_track(
                onset_envelope=onset_env,
                sr=sr,
                units="time"
            )

            if isinstance(tempo, np.ndarray):
                tempo = float(tempo[0])

            tempo_stats = {
                "estimated_tempo": float(tempo),
                "tempo_category": self._categorize_tempo(tempo),
                "beat_count": len(beats) if beats is not None else 0,
            }

            if len(onset_env) > 0:
                onset_autocorr = librosa.autocorrelate(onset_env)
                peak_indices = signal.find_peaks(
                    onset_autocorr[: len(onset_autocorr) // 2]
                )[0]

                if len(peak_indices) > 0 and onset_autocorr[0] != 0:
                    peak_tempos = 60.0 * sr / (512 * peak_indices)
                    closest_peak = peak_indices[np.argmin(np.abs(peak_tempos - tempo))]
                    tempo_confidence = onset_autocorr[closest_peak] / onset_autocorr[0]
                    tempo_stats["tempo_confidence"] = float(tempo_confidence)
                else:
                    tempo_stats["tempo_confidence"] = 0.5
            else:
                tempo_stats["tempo_confidence"] = 0.3

            tempo_stats["estimated_speech_rate_wpm"] = self._estimate_speech_rate(tempo)

            return tempo_stats

        except Exception as e:
            logger.error(f"Tempo calculation failed: {str(e)}")

            duration_seconds = len(audio) / sr if sr > 0 else 60

            energy = np.abs(audio)
            active_frames = np.sum(energy > 0.02)

            estimated_words = max(20, active_frames / 2000)

            estimated_wpm = (
                round((estimated_words / duration_seconds) * 60, 1)
                if duration_seconds > 0
                else 0
            )

            return {
                "estimated_tempo": 0,
                "tempo_category": "unknown",
                "beat_count": 0,
                "tempo_confidence": 0.0,
                "estimated_speech_rate_wpm": estimated_wpm,
            }

    def detect_silence(self, audio: np.ndarray, sr: int) -> Dict[str, Any]:
        try:
            total_duration = len(audio) / sr

            non_silent_intervals = librosa.effects.split(
                audio,
                top_db=abs(self.config.SILENCE_THRESHOLD_DB),
                frame_length=2048,
                hop_length=512
            )

            total_non_silent = 0
            speech_segments = []

            for start, end in non_silent_intervals:
                segment_duration = (end - start) / sr
                total_non_silent += segment_duration
                speech_segments.append({
                    "start": float(start / sr),
                    "end": float(end / sr),
                    "duration": float(segment_duration),
                })

            silence_duration = total_duration - total_non_silent
            silence_ratio = silence_duration / total_duration if total_duration > 0 else 0

            frame_length = int(sr * self.config.MIN_SILENCE_DURATION)
            hop_length = max(1, frame_length // 2)

            rms = librosa.feature.rms(
                y=audio,
                frame_length=frame_length,
                hop_length=hop_length
            )[0]

            silent_frames = np.sum(rms < 0.001)
            total_frames = len(rms)
            short_silence_ratio = silent_frames / total_frames if total_frames > 0 else 0

            silence_stats = {
                "total_duration": float(total_duration),
                "silence_duration": float(silence_duration),
                "speech_duration": float(total_non_silent),
                "silence_ratio": float(silence_ratio),
                "speech_ratio": float(1 - silence_ratio),
                "short_pause_ratio": float(short_silence_ratio),
                "number_of_speech_segments": len(speech_segments),
                "speech_segments": speech_segments,
            }

            if len(speech_segments) > 1:
                pauses = []
                for i in range(len(speech_segments) - 1):
                    pause = speech_segments[i + 1]["start"] - speech_segments[i]["end"]
                    pauses.append(pause)

                silence_stats["pause_mean"] = float(np.mean(pauses))
                silence_stats["pause_std"] = float(np.std(pauses))
                silence_stats["pause_median"] = float(np.median(pauses))
                silence_stats["number_of_pauses"] = len(pauses)
            else:
                silence_stats["pause_mean"] = 0
                silence_stats["pause_std"] = 0
                silence_stats["pause_median"] = 0
                silence_stats["number_of_pauses"] = 0

            return silence_stats

        except Exception as e:
            logger.error(f"Silence detection failed: {str(e)}")
            total_duration = len(audio) / sr if sr > 0 else 0

            return {
                "total_duration": float(total_duration),
                "silence_duration": 0,
                "speech_duration": float(total_duration),
                "silence_ratio": 0,
                "speech_ratio": 1.0,
                "short_pause_ratio": 0,
                "number_of_speech_segments": 1,
                "speech_segments": [
                    {
                        "start": 0,
                        "end": float(total_duration),
                        "duration": float(total_duration),
                    }
                ],
                "pause_mean": 0,
                "pause_std": 0,
                "pause_median": 0,
                "number_of_pauses": 0,
            }

    def calculate_confidence_score(
        self,
        energy_stats: Dict,
        pitch_stats: Dict,
        tempo_stats: Dict,
        silence_stats: Dict
    ) -> float:
        scores = []
        weights = []

        if energy_stats.get("rms_mean", 0) > 0:
            energy_score = min(1.0, energy_stats["rms_mean"] / 0.1)
            scores.append(energy_score)
            weights.append(self.config.ENERGY_WEIGHT)

        tempo = tempo_stats.get("estimated_tempo", 0)

        if tempo > 0 and self.config.OPTIMAL_TEMPO_MIN <= tempo <= self.config.OPTIMAL_TEMPO_MAX:
            tempo_score = 1.0
        elif tempo > 0:
            distance = min(
                abs(tempo - self.config.OPTIMAL_TEMPO_MIN),
                abs(tempo - self.config.OPTIMAL_TEMPO_MAX)
            )
            tempo_score = max(0.3, 1.0 - (distance / 100))
        else:
            tempo_score = 0.5

        scores.append(tempo_score)
        weights.append(self.config.TEMPO_WEIGHT)

        silence_ratio = silence_stats.get("silence_ratio", 0.5)

        if silence_ratio <= 0.3:
            silence_score = 1.0
        elif silence_ratio <= 0.5:
            silence_score = 0.7
        elif silence_ratio <= 0.7:
            silence_score = 0.4
        else:
            silence_score = 0.2

        scores.append(silence_score)
        weights.append(self.config.SILENCE_WEIGHT)

        pitch_stability = pitch_stats.get("pitch_stability", 0.5)
        scores.append(min(1.0, pitch_stability))
        weights.append(self.config.PITCH_WEIGHT)

        voicing_ratio = pitch_stats.get("voicing_ratio", 0.5)
        voice_activity_score = min(1.0, voicing_ratio * 2)
        scores.append(voice_activity_score)
        weights.append(self.config.VOICE_ACTIVITY_WEIGHT)

        total_weight = sum(weights)

        if total_weight > 0:
            confidence = sum(s * w for s, w in zip(scores, weights)) / total_weight
        else:
            confidence = 0.5

        confidence = max(0.0, min(1.0, confidence))

        logger.info(f"Confidence score calculated: {confidence:.3f}")

        return round(float(confidence), 3)

    def analyze(self, file_path: str) -> Dict[str, Any]:
        logger.info(f"Starting audio analysis for: {file_path}")

        try:
            audio, sr = self.load_audio(file_path)
            audio = self.normalize_audio(audio)

            energy_stats = self.calculate_rms_energy(audio)
            pitch_stats = self.calculate_pitch(audio, sr)
            tempo_stats = self.calculate_tempo(audio, sr)
            silence_stats = self.detect_silence(audio, sr)

            confidence_score = self.calculate_confidence_score(
                energy_stats,
                pitch_stats,
                tempo_stats,
                silence_stats
            )

            results = {
                "duration": silence_stats["total_duration"],
                "sample_rate": sr,
                "channels": 1,
                "energy": {
                    "rms_mean": energy_stats["rms_mean"],
                    "rms_std": energy_stats["rms_std"],
                    "energy_db_mean": energy_stats["energy_db_mean"],
                    "energy_db_std": energy_stats["energy_db_std"],
                    "normalized_energy": min(1.0, energy_stats["rms_mean"] / 0.1),
                },
                "pitch": {
                    "mean_f0": pitch_stats["pitch_mean"],
                    "median_f0": pitch_stats["pitch_median"],
                    "std_f0": pitch_stats["pitch_std"],
                    "min_f0": pitch_stats["pitch_min"],
                    "max_f0": pitch_stats["pitch_max"],
                    "voicing_ratio": pitch_stats["voicing_ratio"],
                    "pitch_stability": pitch_stats["pitch_stability"],
                },
                "speech_rate": {
                    "estimated_tempo_bpm": tempo_stats["estimated_tempo"],
                    "tempo_category": tempo_stats["tempo_category"],
                    "tempo_confidence": tempo_stats["tempo_confidence"],
                    "estimated_speech_rate_wpm": tempo_stats["estimated_speech_rate_wpm"],
                },
                "silence": {
                    "total_duration": silence_stats["total_duration"],
                    "silence_duration": silence_stats["silence_duration"],
                    "speech_duration": silence_stats["speech_duration"],
                    "silence_ratio": silence_stats["silence_ratio"],
                    "speech_ratio": silence_stats["speech_ratio"],
                    "short_pause_ratio": silence_stats["short_pause_ratio"],
                    "number_of_pauses": silence_stats["number_of_pauses"],
                    "pause_mean_duration": silence_stats["pause_mean"],
                    "pause_std_duration": silence_stats["pause_std"],
                    "speech_segments_count": silence_stats["number_of_speech_segments"],
                },
                "confidence_score": confidence_score,
                "metadata": {
                    "analyzer_version": "1.0.0",
                    "processing_timestamp": self._get_timestamp(),
                    "config": {
                        "target_sr": self.target_sr,
                        "silence_threshold_db": self.config.SILENCE_THRESHOLD_DB,
                        "pitch_range": [
                            self.config.PITCH_FMIN,
                            self.config.PITCH_FMAX,
                        ],
                    },
                },
            }

            logger.info(f"Analysis complete. Confidence: {confidence_score:.3f}")

            return results

        except Exception as e:
            logger.error(f"Analysis failed: {str(e)}", exc_info=True)
            raise

    def _categorize_tempo(self, tempo: float) -> str:
        if tempo < 90:
            return "slow"
        elif tempo < 140:
            return "medium"
        return "fast"

    def _estimate_speech_rate(self, tempo: float) -> float:
        wpm = tempo * (3.5 / 1.5)
        return round(float(wpm), 1)

    def _get_timestamp(self) -> str:
        return datetime.now(timezone.utc).isoformat()
