import os
from dotenv import load_dotenv

load_dotenv()

class Config:
    """Application configuration."""

    # Server settings
    HOST = os.getenv('AUDIO_SERVICE_HOST', '0.0.0.0')
    PORT = int(os.getenv('AUDIO_SERVICE_PORT', 5001))
    DEBUG = os.getenv('AUDIO_SERVICE_DEBUG', 'False').lower() == 'true'

    # Audio processing settings
    MAX_FILE_SIZE_MB = int(os.getenv('MAX_FILE_SIZE_MB', 50))
    ALLOWED_EXTENSIONS = {'wav', 'mp3', 'flac', 'm4a', 'ogg', 'webm'}
    TARGET_SAMPLE_RATE = int(os.getenv('TARGET_SAMPLE_RATE', 22050))

    # Analysis parameters
    SILENCE_THRESHOLD_DB = int(os.getenv('SILENCE_THRESHOLD_DB', -40))
    MIN_SILENCE_DURATION = float(os.getenv('MIN_SILENCE_DURATION', 0.1))
    PITCH_FMIN = float(os.getenv('PITCH_FMIN', 50.0))
    PITCH_FMAX = float(os.getenv('PITCH_FMAX', 500.0))

    # Scoring weights
    ENERGY_WEIGHT = float(os.getenv('ENERGY_WEIGHT', 0.25))
    TEMPO_WEIGHT = float(os.getenv('TEMPO_WEIGHT', 0.25))
    SILENCE_WEIGHT = float(os.getenv('SILENCE_WEIGHT', 0.20))
    PITCH_WEIGHT = float(os.getenv('PITCH_WEIGHT', 0.15))
    VOICE_ACTIVITY_WEIGHT = float(os.getenv('VOICE_ACTIVITY_WEIGHT', 0.15))

    # Optimal ranges for scoring
    OPTIMAL_TEMPO_MIN = int(os.getenv('OPTIMAL_TEMPO_MIN', 100))
    OPTIMAL_TEMPO_MAX = int(os.getenv('OPTIMAL_TEMPO_MAX', 180))
    OPTIMAL_SILENCE_RATIO = float(os.getenv('OPTIMAL_SILENCE_RATIO', 0.15))

    # Logging
    LOG_LEVEL = os.getenv('LOG_LEVEL', 'INFO')
    LOG_FILE = os.getenv('LOG_FILE', 'audio_service.log')
