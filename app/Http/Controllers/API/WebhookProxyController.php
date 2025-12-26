<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookProxyController extends Controller
{
    /**
     * Принимает webhook от TAS, обогащает данными (координаты) и отправляет на конечный webhook
     */
    public function handle(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->all();
        
        Log::info('Webhook proxy received', [
            'data_keys' => array_keys($data),
            'has_media' => isset($data['media']),
        ]);

        // Обогащаем данные
        $enrichedData = $this->enrichWebhookData($data);

        // Получаем конечный webhook URL из query параметра или заголовка
        $targetWebhookUrl = $request->query('target_url') ?? $request->header('X-Target-Webhook');

        if (!$targetWebhookUrl) {
            Log::warning('No target webhook URL provided');
            return response()->json([
                'success' => false,
                'error' => 'No target webhook URL provided',
            ], 400);
        }

        // Отправляем на конечный webhook
        try {
            $response = Http::timeout(10)->post($targetWebhookUrl, $enrichedData);

            Log::info('Webhook forwarded', [
                'target_url' => $targetWebhookUrl,
                'status' => $response->status(),
            ]);

            return response()->json([
                'success' => true,
                'forwarded' => true,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to forward webhook', [
                'target_url' => $targetWebhookUrl,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to forward webhook: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обогащаем webhook данные дополнительной информацией
     */
    private function enrichWebhookData(array $data): array
    {
        // Добавляем координаты для geo сообщений
        if (isset($data['media']['type']) && $data['media']['type'] === 'messageMediaGeo') {
            $data['media'] = $this->extractGeoCoordinates($data);
        }

        return $data;
    }

    /**
     * Извлекаем координаты из geo сообщения
     */
    private function extractGeoCoordinates(array $data): array
    {
        $media = $data['media'] ?? [];
        
        // Координаты могут быть в raw данных
        $rawUpdate = $data['raw'] ?? [];
        $rawMessage = $rawUpdate['message'] ?? [];
        $rawMedia = $rawMessage['media'] ?? [];
        
        // Извлекаем координаты из geo объекта
        $geo = $rawMedia['geo'] ?? null;
        
        if ($geo && isset($geo['lat']) && isset($geo['long'])) {
            $media['latitude'] = $geo['lat'];
            $media['longitude'] = $geo['long'];
            $media['lat'] = $geo['lat'];
            $media['lng'] = $geo['long'];
        }

        return $media;
    }
}

