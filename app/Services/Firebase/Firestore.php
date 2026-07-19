<?php
namespace App\Services\Firebase;

use App\Models\Contact;
use Kreait\Firebase\Factory;

class Firestore
{
    protected $firestore;
    
    public function __construct()
    {
        $factory = (new Factory)
        ->withServiceAccount(resource_path('firebase/wazz-chat-firebase-adminsdk-fbsvc-bd4e1c725b.json'))
        ->withDatabaseUri('https://wazz-chat-default-rtdb.firebaseio.com');
        $firestore = $factory->createFirestore();
        $this->firestore = $firestore;
    }
    public function setNewMessageReceived(int $contactId, array $additionalDataToBeSent = [])
    {
        $collectionName = 'contact_messages';
        $reference = $this->firestore->database()->collection($collectionName)->document($contactId);
        $firebaseDataToBeSent = array_merge([], $additionalDataToBeSent);
        $reference->set($firebaseDataToBeSent);
    }
    

}
