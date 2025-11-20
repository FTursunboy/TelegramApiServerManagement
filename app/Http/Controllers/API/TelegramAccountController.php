<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartLoginRequest;
use App\Http\Requests\CompleteCodeRequest;
use App\Http\Requests\Complete2FARequest;
use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\SendVoiceRequest;
use App\Http\Requests\SendFileRequest;
use App\Http\Requests\SessionRequest;
use App\Models\TelegramAccount;
use App\Services\MessageService;
use App\Services\TelegramAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TelegramAccountController extends Controller
{
    public function __construct(
        private TelegramAccountService $accountService,
        private MessageService $messageService,
    ) {}

    public function startLogin(StartLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->accountService->startLogin($request->validated());

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Start login failed', [
                'request' => $request->except(['api_hash', 'bot_token']),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function completeCode(CompleteCodeRequest $request): JsonResponse
    {
        try {
            $result = $this->accountService->completeCode(
                $request->session_name,
                $request->code
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function complete2FA(Complete2FARequest $request): JsonResponse
    {
        try {
            $result = $this->accountService->complete2FA(
                $request->session_name,
                $request->password
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function stop(SessionRequest $request): JsonResponse
    {
        try {
            $result = $this->accountService->stop(
                $request->session_name,
                $request->remove_container ?? true
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Stop failed', [
                'session_name' => $request->session_name,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function restart(SessionRequest $request): JsonResponse
    {
        try {
            $account = TelegramAccount::where('session_name', $request->session_name)
                ->with('telegramApp')
                ->firstOrFail();

            $this->accountService->stop($request->session_name, removeContainer: true);

            $result = $this->accountService->startLogin([
                'api_id' => $account->telegramApp->api_id,
                'api_hash' => $account->telegramApp->api_hash,
                'type' => $account->type->value,
                'phone' => $account->phone,
                'bot_token' => $account->bot_token,
                'webhook_url' => $account->webhook_url,
                'session_name' => $account->session_name,
                'force_recreate' => true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Session restarted',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Restart failed', [
                'session_name' => $request->session_name,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function status(SessionRequest $request): JsonResponse
    {
        try {
            $account = TelegramAccount::where('session_name', $request->session_name)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'session_name' => $account->session_name,
                    'status' => $account->status->value,
                    'type' => $account->type->value,
                    'has_container' => $account->hasContainer(),
                    'container_name' => $account->container_name,
                    'container_port' => $account->container_port,
                    'phone' => $account->phone,
                    'telegram_username' => $account->telegram_username,
                    'first_name' => $account->first_name,
                    'last_error' => $account->last_error,
                    'created_at' => $account->created_at,
                    'updated_at' => $account->updated_at,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Session not found',
            ], 404);
        }
    }

    public function sendMessage(SendMessageRequest $request): JsonResponse
    {
        try {
            $account = TelegramAccount::where('session_name', $request->session_name)
                ->firstOrFail();

            $result = $this->messageService->send(
                account: $account,
                peer: $request->peer,
                message: $request->message,
                parseMode: $request->parse_mode ?? 'Markdown'
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Send message failed', [
                'session_name' => $request->session_name,
                'peer' => $request->peer,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function sendVoice(SendVoiceRequest $request): JsonResponse
    {
        try {
            $account = TelegramAccount::where('session_name', $request->session_name)
                ->firstOrFail();

            $result = $this->messageService->sendVoice(
                account: $account,
                peer: $request->peer,
                voicePath: $request->voice_path,
                caption: $request->caption
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Send voice failed', [
                'session_name' => $request->session_name,
                'peer' => $request->peer,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function sendFile(SendFileRequest $request): JsonResponse
    {
        try {
            $account = TelegramAccount::where('session_name', $request->session_name)
                ->firstOrFail();

            $result = $this->messageService->sendFile(
                account: $account,
                peer: $request->peer,
                filePath: $request->file_path,
                caption: $request->caption,
                parseMode: $request->parse_mode
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Send file failed', [
                'session_name' => $request->session_name,
                'peer' => $request->peer,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
