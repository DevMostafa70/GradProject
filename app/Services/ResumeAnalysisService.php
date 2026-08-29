<?php

namespace App\Services;

use App\Models\Resume;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpWord\IOFactory;

class ResumeAnalysisService
{
    protected LLMService $llmService;

    public function __construct(LLMService $llmService)
    {
        $this->llmService = $llmService;
    }

    /**
 * Upload and store resume file
 */
public function upload($user, UploadedFile $file, ?string $targetPosition = null, ?array $targetSkills = null): Resume
{
    //  التحقق الإضافي من حجم الملف (بالكيلوبايت)
    $maxSizeKB = config('app.max_resume_size', 5120);
    $maxSizeBytes = $maxSizeKB * 1024; // تحويل إلى بايت

    if ($file->getSize() > $maxSizeBytes) {
        throw new \Exception('حجم الملف (' . round($file->getSize() / 1024, 2) . ' KB) يتجاوز الحد المسموح (' . $maxSizeKB . ' KB).');
    }

    // التحقق من أن الملف قابل للقراءة
    if (!$file->isReadable()) {
        throw new \Exception('الملف غير قابل للقراءة.');
    }

    // Generate unique filename
    $originalName = $file->getClientOriginalName();
    $extension = $file->getClientOriginalExtension();
    $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);

    // Store file privately. The configured disk may be local or remote.
    $disk = (string) config('uploads.private_disk', 'local');
    $path = $file->storeAs('resumes', $fileName, $disk);

    if ($path === false) {
        throw new \RuntimeException('Failed to store the resume file.');
    }

    // Create resume record
    $resume = Resume::create([
        'user_id' => $user->id,
        'file_path' => $path,
        'file_name' => $originalName,
        'file_type' => $extension,
        'target_position' => $targetPosition,
        'target_skills' => $targetSkills,
        'status' => 'pending',
    ]);

    return $resume;
}

    /**
     * Extract text from uploaded file
     */
    public function extractText(Resume $resume): string
    {
        $temporaryPath = null;

        try {
            $temporaryPath = $this->prepareLocalResumeFile($resume);
            $text = '';

            switch (strtolower($resume->file_type)) {
                case 'pdf':
                    $text = $this->extractFromPdf($temporaryPath);
                    break;
                case 'docx':
                    $text = $this->extractFromDocx($temporaryPath);
                    break;
                case 'txt':
                    $text = file_get_contents($temporaryPath);
                    break;
                default:
                    throw new \Exception('Unsupported file type: ' . $resume->file_type);
            }

            // Clean text (remove extra spaces, normalize line breaks)
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            // Update resume with extracted text
            $resume->update([
                'extracted_text' => $text,
                'status' => 'processing',
            ]);

            return $text;
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * Extract text from PDF
     */
    private function extractFromPdf(string $path): string
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($path);
            return $pdf->getText();
        } catch (\Exception $e) {
            // Fallback: try basic text extraction
            return $this->basicPdfExtraction($path);
        }
    }

    /**
     * Basic PDF text extraction (fallback)
     */
    private function basicPdfExtraction(string $path): string
    {
        $content = file_get_contents($path);

        // Remove PDF binary parts
        $content = preg_replace('/\/[A-Za-z0-9\s]+<<.*?>>/s', '', $content);
        $content = preg_replace('/stream.*?endstream/s', '', $content);

        // Extract readable text
        preg_match_all('/\(([^)]+)\)/', $content, $matches);

        return implode(' ', $matches[1] ?? []);
    }

    /**
     * Extract text from DOCX
     */
    private function extractFromDocx(string $path): string
    {
        try {
            $phpWord = IOFactory::load($path);
            $text = '';

            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText')) {
                        $text .= $element->getText() . ' ';
                    }
                }
            }

            return $text;
        } catch (\Exception $e) {
            throw new \Exception('Failed to extract text from DOCX: ' . $e->getMessage());
        }
    }

    /**
     * Analyze resume using AI
     */
    public function analyze(Resume $resume): array
    {
        Log::info('=== ResumeAnalysisService::analyze START ===');

        if (!$resume->extracted_text) {
            Log::info('Extracting text from file...');
            $this->extractText($resume);
            Log::info('Extracted text length: ' . strlen($resume->extracted_text));
        }

        Log::info('Calling LLMService::analyzeResume...');
        $analysis = $this->llmService->analyzeResume(
            $resume->extracted_text,
            $resume->target_position,
            $resume->target_skills
        );

        Log::info('LLMService response received');

        $resume->update([
            'analysis_result' => $analysis,
            'ats_score' => $analysis['ats_score'] ?? null,
            'analyzed_at' => now(),
            'status' => 'completed',
        ]);

        Log::info('=== ResumeAnalysisService::analyze END ===');

        return $analysis;
    }

    /**
     * Get resume improvement suggestions
     */
    public function getImprovements(Resume $resume): array
    {
        if (!$resume->analysis_result) {
            $this->analyze($resume);
        }

        $improvements = $this->llmService->improveResume(
            $resume->extracted_text,
            $resume->analysis_result,
            $resume->target_position
        );

        $resume->update([
            'improved_content' => $improvements,
        ]);

        return $improvements;
    }

    /**
     * Get latest resume for user
     */
    public function getLatestResume($user): ?Resume
    {
        return $user->resumes()
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Delete resume and its file
     */
    public function delete(Resume $resume): bool
    {
        // Delete file from storage
        $storage = Storage::disk((string) config('uploads.private_disk', 'local'));

        if ($storage->exists($resume->file_path)) {
            $storage->delete($resume->file_path);
        }

        return $resume->delete();
    }

    private function prepareLocalResumeFile(Resume $resume): string
    {
        $diskName = (string) config('uploads.private_disk', 'local');
        $storage = Storage::disk($diskName);

        if (!$storage->exists($resume->file_path)) {
            throw new \RuntimeException("Resume file not found on disk [{$diskName}].");
        }

        $source = $storage->readStream($resume->file_path);

        if ($source === false) {
            throw new \RuntimeException("Unable to read resume file from disk [{$diskName}].");
        }

        $basePath = tempnam(sys_get_temp_dir(), 'resume_');

        if ($basePath === false) {
            fclose($source);
            throw new \RuntimeException('Unable to create a temporary resume file.');
        }

        $temporaryPath = $basePath;
        $extension = strtolower((string) $resume->file_type);

        if ($extension !== '' && preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1) {
            $temporaryPath = $basePath . '.' . $extension;

            if (!rename($basePath, $temporaryPath)) {
                fclose($source);
                @unlink($basePath);
                throw new \RuntimeException('Unable to prepare the temporary resume filename.');
            }
        }

        $destination = fopen($temporaryPath, 'wb');

        if ($destination === false) {
            fclose($source);
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to open the temporary resume file for writing.');
        }

        $copyException = null;

        try {
            if (stream_copy_to_stream($source, $destination) === false) {
                throw new \RuntimeException('Unable to copy the stored resume into a temporary file.');
            }
        } catch (\Throwable $e) {
            $copyException = $e;
        } finally {
            fclose($source);
            fclose($destination);
        }

        if ($copyException !== null) {
            @unlink($temporaryPath);
            throw $copyException;
        }

        return $temporaryPath;
    }
}
