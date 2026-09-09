<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\EcommerceOrderVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class OrderVerificationController extends Controller
{
    /**
     * Get list of verifications for current user/site
     */
    public function index(Request $request)
    {
        $siteSlug = $request->get('site_slug', 'embroidery');
        $user = $request->user();

        // If no user is authenticated, do not return any records
        if (!$user) {
            return response()->json([
                'success' => true,
                'data' => [],
                'stats' => [
                    'total_requests' => 0,
                    'pending_documents' => 0,
                    'completed' => 0,
                    'action_required' => 0,
                    'declined' => 0,
                ]
            ]);
        }

        $query = EcommerceOrderVerification::query()
            ->where(function($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereHas('order', function($oq) use ($user) {
                      $oq->where('user_id', $user->id)
                         ->orWhere('customer_email', $user->email);
                  });
            });

        if ($request->filled('status')) {
            $status = $request->get('status');
            if ($status === 'completed') {
                $query->whereIn('status', ['verified', 'completed', 'cleared']);
            } elseif ($status === 'pending' || $status === 'pending_documents') {
                $query->whereIn('status', ['pending', 'pending_documents', 'reviewing', 'submitted']);
            } elseif ($status === 'action_required') {
                $query->where('status', 'action_required');
            } elseif ($status === 'declined') {
                $query->where('status', 'declined');
            } else {
                $query->where('status', $status);
            }
        }

        $verifications = $query->latest()->get();

        // Calculate summary statistics strictly for THIS USER only
        $userVerifications = EcommerceOrderVerification::query()
            ->where(function($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereHas('order', function($oq) use ($user) {
                      $oq->where('user_id', $user->id)
                         ->orWhere('customer_email', $user->email);
                  });
            })
            ->get();

        $totalRequests = $userVerifications->count();
        $pendingCount = $userVerifications->whereIn('status', ['pending', 'pending_documents', 'reviewing'])->count();
        $completedCount = $userVerifications->whereIn('status', ['verified', 'completed', 'cleared'])->count();
        $actionRequiredCount = $userVerifications->where('status', 'action_required')->count();
        $declinedCount = $userVerifications->where('status', 'declined')->count();

        return response()->json([
            'success' => true,
            'data' => $verifications,
            'stats' => [
                'total_requests' => $totalRequests,
                'pending_documents' => $pendingCount,
                'completed' => $completedCount,
                'action_required' => $actionRequiredCount,
                'declined' => $declinedCount,
            ]
        ]);
    }

    /**
     * PostgreSQL safe finder for verification by ID or order_number
     */
    protected function findVerificationForUser($id, $user)
    {
        return EcommerceOrderVerification::where(function($q) use ($id) {
                $q->where('order_number', (string) $id);
                if (is_numeric($id)) {
                    $q->orWhere('id', (int) $id);
                }
            })
            ->where(function($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereHas('order', function($oq) use ($user) {
                      $oq->where('user_id', $user->id)
                         ->orWhere('customer_email', $user->email);
                  });
            })
            ->firstOrFail();
    }

    /**
     * Get single verification detail
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $verification = $this->findVerificationForUser($id, $user);

        return response()->json([
            'success' => true,
            'data' => $verification
        ]);
    }

    /**
     * Upload verification documents
     */
    public function uploadDocuments(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $verification = $this->findVerificationForUser($id, $user);

        $request->validate([
            'document_type' => 'nullable|string',
            'note' => 'nullable|string',
            'files.*' => 'nullable|file|max:15360', // 15MB max per file
        ]);

        $submittedDocs = $verification->submitted_documents ?? [];
        $uploadedUrls = [];
        $fileTypes = $request->input('file_types', []);

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $idx => $file) {
                $path = $file->store('verifications/customer_uploads', 'public');
                $category = isset($fileTypes[$idx]) && !empty($fileTypes[$idx])
                    ? $fileTypes[$idx]
                    : $request->input('document_type', $file->getClientOriginalName());
                
                $docName = $category . ' (' . $file->getClientOriginalName() . ')';
                $type = strtolower(str_contains($category, 'Card') ? 'card' : (str_contains($category, 'ID') ? 'id' : 'document'));

                $newDoc = [
                    'id' => 'doc_' . uniqid(),
                    'name' => $docName,
                    'category' => $category,
                    'type' => $type,
                    'file_url' => Storage::disk('public')->url($path),
                    'status' => 'submitted',
                    'submitted_at' => Carbon::now()->format('M d, Y • h:i A')
                ];

                // Match existing placeholder by category
                $matchedIndex = -1;
                foreach ($submittedDocs as $sIdx => $sDoc) {
                    $sName = $sDoc['name'] ?? '';
                    if (stripos($sName, $category) !== false || stripos($category, $sName) !== false) {
                        $matchedIndex = $sIdx;
                        break;
                    }
                }

                if ($matchedIndex >= 0) {
                    $submittedDocs[$matchedIndex] = $newDoc;
                } else {
                    $submittedDocs[] = $newDoc;
                }

                $uploadedUrls[] = $newDoc;
            }
        } else {
            // Simulated / Mock submission
            $docType = $request->input('document_type', 'Supporting Document');
            $newDoc = [
                'id' => 'doc_' . uniqid(),
                'name' => $docType,
                'type' => 'document',
                'status' => 'submitted',
                'submitted_at' => Carbon::now()->format('M d, Y • h:i A')
            ];

            $matchedIndex = -1;
            foreach ($submittedDocs as $sIdx => $sDoc) {
                $sName = $sDoc['name'] ?? '';
                if (stripos($sName, $docType) !== false || stripos($docType, $sName) !== false) {
                    $matchedIndex = $sIdx;
                    break;
                }
            }

            if ($matchedIndex >= 0) {
                $submittedDocs[$matchedIndex] = $newDoc;
            } else {
                $submittedDocs[] = $newDoc;
            }
        }

        // Check if all required documents now have at least one submitted file
        $requiredDocs = $verification->required_documents ?? [];
        $allSubmitted = true;
        if (!empty($requiredDocs)) {
            foreach ($requiredDocs as $req) {
                $found = false;
                foreach ($submittedDocs as $sd) {
                    if (($sd['status'] ?? '') === 'submitted' && (
                        stripos($sd['name'] ?? '', $req) !== false ||
                        stripos($req, $sd['name'] ?? '') !== false ||
                        stripos($sd['category'] ?? '', $req) !== false
                    )) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $allSubmitted = false;
                    break;
                }
            }
        }

        // Update Timeline
        $timeline = $verification->timeline ?? [];
        $timeline[] = [
            'title' => 'Customer Response Submitted',
            'date' => Carbon::now()->format('M d, Y • h:i A'),
            'completed' => true
        ];

        $verification->update([
            'submitted_documents' => array_values($submittedDocs),
            'status' => 'pending_documents',
            'customer_notes' => $request->input('note', $verification->customer_notes),
            'timeline' => $timeline,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Verification documents uploaded successfully!',
            'data' => $verification->fresh()
        ]);
    }

    /**
     * Add note / explanation from customer
     */
    public function addNote(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $verification = $this->findVerificationForUser($id, $user);

        $request->validate([
            'note' => 'required|string|max:2000'
        ]);

        $notes = $verification->internal_notes ?? [];
        $notes[] = 'Customer Note (' . Carbon::now()->format('M d, Y h:i A') . '): ' . $request->input('note');

        $verification->update([
            'customer_notes' => $request->input('note'),
            'internal_notes' => $notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Note added successfully!',
            'data' => $verification->fresh()
        ]);
    }
}
