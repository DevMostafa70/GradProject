"""
Flask web service for audio analysis.
Provides REST API endpoint for real-time audio processing.
"""

import os
import logging
import tempfile
from datetime import datetime
from flask import Flask, request, jsonify
from flask_cors import CORS
from werkzeug.utils import secure_filename

from config import Config
from audio_analyzer import AudioAnalyzer

# Initialize Flask app
app = Flask(__name__)
CORS(app)

# Load configuration
app.config.from_object(Config)
config = Config()

# Setup logging
logging.basicConfig(
    level=getattr(logging, config.LOG_LEVEL.upper(), logging.INFO),
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler(config.LOG_FILE),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

# Initialize analyzer
analyzer = AudioAnalyzer(config)


def allowed_file(filename: str) -> bool:
    """Check if file extension is allowed."""
    return '.' in filename and \
           filename.rsplit('.', 1)[1].lower() in config.ALLOWED_EXTENSIONS


def validate_audio_file(file) -> str:
    """
    Validate uploaded audio file.

    Args:
        file: File from request

    Returns:
        Path to validated file

    Raises:
        ValueError: If validation fails
    """
    if not file:
        raise ValueError("No file provided")

    if file.filename == '':
        raise ValueError("Empty filename")

    if not allowed_file(file.filename):
        raise ValueError(
            f"File type not allowed. Allowed: {', '.join(config.ALLOWED_EXTENSIONS)}"
        )

    # Check file size
    file.seek(0, os.SEEK_END)
    file_size = file.tell()
    file.seek(0)

    max_bytes = config.MAX_FILE_SIZE_MB * 1024 * 1024
    if file_size > max_bytes:
        raise ValueError(
            f"File too large: {file_size / (1024*1024):.2f}MB. "
            f"Maximum: {config.MAX_FILE_SIZE_MB}MB"
        )

    # Save file to temporary location
    filename = secure_filename(file.filename)
    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S_%f')
    temp_filename = f"{timestamp}_{filename}"
    temp_path = os.path.join(tempfile.gettempdir(), temp_filename)

    file.save(temp_path)
    logger.info(f"File saved to: {temp_path}")

    return temp_path


@app.route('/health', methods=['GET'])
def health_check():
    """Health check endpoint."""
    return jsonify({
        'status': 'healthy',
        'service': 'audio-analysis',
        'version': '1.0.0',
        'timestamp': datetime.utcnow().isoformat(),
        "working" : "I am here form python"
    })


@app.route('/analyze', methods=['POST'])
def analyze_audio():
    """
    Analyze audio file endpoint.

    Expected: POST with multipart/form-data
    - audio_file: Audio file (WAV, MP3, FLAC, etc.)

    Returns: JSON with analysis results
    """
    temp_file_path = None

    try:
        # Validate request
        if 'audio_file' not in request.files:
            return jsonify({
                'error': 'No audio file provided',
                'detail': 'Please include an audio_file in the request'
            }), 400

        file = request.files['audio_file']

        # Validate and save file
        try:
            temp_file_path = validate_audio_file(file)
        except ValueError as e:
            return jsonify({
                'error': 'File validation failed',
                'detail': str(e)
            }), 400

        # Analyze audio
        try:
            results = analyzer.analyze(temp_file_path)
        except Exception as e:
            logger.error(f"Analysis failed: {str(e)}", exc_info=True)
            return jsonify({
                'error': 'Audio analysis failed',
                'detail': str(e)
            }), 500

        # Prepare response
        response = {
            'success': True,
            'data': results,
            'filename': file.filename,
            'analyzed_at': datetime.utcnow().isoformat(),
        }

        logger.info(f"Analysis complete for: {file.filename}")
        return jsonify(response), 200

    except Exception as e:
        logger.error(f"Unexpected error: {str(e)}", exc_info=True)
        return jsonify({
            'error': 'Internal server error',
            'detail': 'An unexpected error occurred during analysis'
        }), 500

    finally:
        # Clean up temporary file
        if temp_file_path and os.path.exists(temp_file_path):
            try:
                os.unlink(temp_file_path)
                logger.debug(f"Cleaned up temporary file: {temp_file_path}")
            except Exception as e:
                logger.warning(f"Failed to clean up temp file: {str(e)}")


@app.route('/analyze/batch', methods=['POST'])
def analyze_batch():
    """
    Batch analysis endpoint for multiple files.

    Expected: POST with multipart/form-data
    - audio_files: Multiple audio files

    Returns: JSON with array of analysis results
    """
    results = []

    if 'audio_files' not in request.files:
        return jsonify({
            'error': 'No audio files provided'
        }), 400

    files = request.files.getlist('audio_files')

    if len(files) > 10:
        return jsonify({
            'error': 'Too many files',
            'detail': 'Maximum 10 files per batch'
        }), 400

    for file in files:
        temp_file_path = None
        try:
            temp_file_path = validate_audio_file(file)
            analysis = analyzer.analyze(temp_file_path)
            results.append({
                'filename': file.filename,
                'success': True,
                'data': analysis,
            })
        except Exception as e:
            results.append({
                'filename': file.filename,
                'success': False,
                'error': str(e),
            })
        finally:
            if temp_file_path and os.path.exists(temp_file_path):
                os.unlink(temp_file_path)

    return jsonify({
        'success': True,
        'batch_size': len(files),
        'results': results,
    }), 200


@app.errorhandler(404)
def not_found(error):
    """Handle 404 errors."""
    return jsonify({
        'error': 'Endpoint not found',
        'detail': str(error)
    }), 404


@app.errorhandler(413)
def request_entity_too_large(error):
    """Handle file too large errors."""
    return jsonify({
        'error': 'File too large',
        'detail': f'Maximum file size: {config.MAX_FILE_SIZE_MB}MB'
    }), 413


@app.errorhandler(500)
def internal_error(error):
    """Handle 500 errors."""
    logger.error(f"Internal server error: {str(error)}")
    return jsonify({
        'error': 'Internal server error',
        'detail': 'An unexpected error occurred'
    }), 500


if __name__ == '__main__':
    logger.info(f"Starting Audio Analysis Service on {config.HOST}:{config.PORT}")
    app.run(
        host=config.HOST,
        port=config.PORT,
        debug=config.DEBUG
    )
