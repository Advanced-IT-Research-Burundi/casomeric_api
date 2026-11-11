<?php

namespace App\Http\Controllers;

use App\Models\Membre;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Response;

class NotificationController extends Controller
{
    // GET /notifications
    public function index(Request $request)
    {
        $user = Auth::user();

        $notifications = Notification::where('assignee_id', $user->id)->get();
        return sendResponse($notifications, 'Notifications récupérées avec succès.');
    }

    // POST /notifications
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string'],
            'time' => ['nullable', 'date'],
            'read' => ['nullable', 'boolean'],
        ]);

        $data['user_id'] = Auth::id();
        $data['assignee_id'] = Auth::id();
        $data['read'] = $data['read'] ?? false;

        $notification = Notification::create($data);

        return sendResponse($notification, 'Notification créée avec succès.');
    }

    // GET /notifications/{id}
    public function show(Notification $notification)
    {
        $this->authorizeOwner($notification);
        return sendResponse($notification, 'Notification récupérée avec succès.');
    }

    // PUT/PATCH /notifications/{id}
    public function update(Request $request, Notification $notification)
    {
        $this->authorizeOwner($notification);

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string'],
            'time' => ['nullable', 'date'],
            'read' => ['nullable', 'boolean'],
        ]);

        $notification->update($data);

        return sendResponse($notification, 'Notification mise à jour avec succès.');
    }

    // DELETE /notifications/{id}
    public function destroy(Notification $notification)
    {
        $this->authorizeOwner($notification);
        $notification->delete();
        return sendResponse(null, 'Notification supprimée avec succès.');
    }

    // POST /notifications/{notification}/mark-read
    public function markAsRead(Notification $notification)
    {
        $this->authorizeOwner($notification);
        if (!$notification->read) {
            $notification->forceFill(['read' => true])->save();
        }
        return sendResponse($notification, 'Notification marquée comme lue avec succès.');
    }

    // POST /notifications/mark-all-read
    public function markAllAsRead()
    {
        Notification::where('assignee_id', Auth::id())
            ->where('read', false)
            ->update(['read' => true]);

        return sendResponse(null, 'Toutes les notifications ont été marquées comme lues.');
    }

    // GET /notifications-unread-count
    public function unreadCount()
    {
        $count = Notification::where('assignee_id', Auth::id())->where('read', false)->count();
        return sendResponse(['unread' => $count], 'Nombre de notifications non lues récupéré avec succès.');
    }

    // Vérifie que la notification est destinée à l'utilisateur connecté (assignee_id).
    // user_id est l'auteur de l'action, pas le destinataire.
    protected function authorizeOwner(Notification $notification): void
    {
        abort_if((int) $notification->assignee_id !== (int) Auth::id(), Response::HTTP_FORBIDDEN, 'Accès refusé.');
    }
}
