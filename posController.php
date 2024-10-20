<?php

namespace App\Http\Controllers\api\v2\seller;

use App\CPU\BackEndHelper;

use App\CPU\Convert;

use App\CPU\Helpers;
use App\CPU\OrderManager;
use App\CPU\ImageManager;

use App\Http\Controllers\Controller;

use App\Model\DeliveryMan;

use App\Model\OrderTransaction;

use App\Model\Product;

use App\Model\Review;

use App\Model\Seller;

use App\Model\Cart;

use App\User;

use App\Model\Location;

use App\Model\SellerWallet;

use App\Model\Shop;
use App\Model\Order;
use App\Model\OrderDetail;
use App\Model\WithdrawRequest;

use Illuminate\Http\Request;

use Illuminate\Support\Carbon;

use Illuminate\Support\Facades\DB;

use Illuminate\Support\Str;

use function App\CPU\translate;
use Illuminate\Support\Facades\Mail; 

use Illuminate\Support\Facades\Validator;

class PosController extends Controller
{
    public function check_ticket(Request $request)
    {
        $data = Helpers::get_seller_by_token($request);
     
        if ($data['success'] == 1) {
            $seller = $data['data'];
            $cartIds = explode(",", $request['cart_id']);
            // dd($cartIds); 
            $cart = DB::table('carts')
                ->whereIn('carts.id', $cartIds)
                ->get();
            $loginDetail = DB::table('login_details')
                    ->where('vendor_id', $seller->id)
                    ->whereNull('logout_time')
                    ->latest()
                    ->first();
            $location = DB::table('locations')->where('id', $loginDetail->location_id)->first();
           // $errorMessage= '';
           
            $check_lo = DB::table('orders')->where([
            'currentDate' => $request['booking_date'] != null ? $request['booking_date'] : date('Y-m-d'),
            'location_id' => $loginDetail->location_id,
            'timeslot' => $request['timeslot']
            ])->get();

            $cn = 0;

            $check_d = DB::table('orders')->where([
            'currentDate' => $request['booking_date'] != null ? $request['booking_date'] : date('Y-m-d'),
            'location_id' => $loginDetail->location_id,
            'timeslot' => $request['timeslot']
            ])->pluck('id')->toArray();

            $times = explode(" to ", $request['timeslot']);

            $start_time = strtotime($times[0]); // Convert start time to timestamp
            $end_time = strtotime($times[1]);   // Convert end time to timestamp

            $time_difference = $end_time - $start_time; // Calculate time difference in seconds

            $time_difference_minutes = $time_difference / 60;
            $time_difference_hr = $time_difference_minutes / 60;
            
            foreach ($cart as $c) {
            $cn += $c->quantity;
            }
            $or_q = DB::table('order_details')->whereIn('order_id', $check_d)->pluck('qty')->toArray();
            $cd = 0;
            foreach ($or_q as $cs) {
            $cd += (int)$cs; 
            }
           
            if( $cn <= 20){
            if($cd + $cn <= $time_difference_hr *(int)$location->tickestperhr){
            if (!$check_lo->isEmpty()) {
                $errorMessage = [
                    'message' => 'now tickets are avaialable',
                    'status' => true,
                ];
            if ($time_difference_hr * (int)$location->tickestperhr >= $cd) {
            } else {
           
            $errorMessage = [
                'message' => 'current slot seat is full',
                'status' => true,
            ];
            }
            } else {
                $errorMessage = [
                    'message' => 'now tickets are avaialable',
                    'status' => true,
                ];
            }
            }else {
            $errorMessage = [
                'message' => 'Only ' . ($time_difference_hr * (int)$location->tickestperhr - $cd  ) . ' tickets are now available in this slot.!',
                'status' => false,
            ];
            }
            }
            else{
          
            $errorMessage = [
                'message' => 'Maximum 20 tickets are allowed!',
                'status' => false,
            ];
            }
            return response()->json($errorMessage);
        } else {
            return response()->json(
                [
                    'auth-001' => translate('Your existing session token does not authorize you anymore'),
                ],
                401
            );
        }
    }
    

    public function checktkt($request, $seller){

 $cartIds = explode(",", $request['cart_id']);
 // dd($cartIds); 
  $cart = DB::table('carts')
      ->whereIn('carts.id', $cartIds)
      ->get();


$loginDetail = DB::table('login_details')
         ->where('vendor_id', $seller->id)
         ->whereNull('logout_time')
         ->latest()
         ->first();
$location = DB::table('locations')->where('id', $loginDetail->location_id)->first();
// $sequence = []; // Initialize $sequence as an array
$errorMessage= '';


$total = DB::table('carts')
    ->whereIn('carts.id', $cartIds)
    // ->select(DB::raw('SUM(quantity * price) as total'))
    // ->value('total');
    ->sum('price');
//dd($total);
$itemPrice = $total;
$cgst = $location->cgst;
$sgst= $location->sgst;;

$totalc = OrderManager::pricecaliculation($itemPrice, $cgst, $sgst);
//dd($totalc['totalp'] ,);
if ($totalc['totalp'] != (float)$request['amount']) {
   // $errorMessage = $itemPrice . ' ' . $cgst . ' ' . $sgst.'/'.count($cart)." ".count($cart); 

 
    $errorMessage = "The total amount ($totalc[totalp]) does not match the requested amount (" . (float)$request['amount'] . ").";
    
    error_log(print_r($cart, true));
}

// Query orders based on the provided date, location, and timeslot
$check_lo = DB::table('orders')->where([
'currentDate' => $request['booking_date'] != null ? $request['booking_date'] : date('Y-m-d'),
'location_id' => $loginDetail->location_id,
'timeslot' => $request['timeslot']
])->get();

$cn = 0;

// Query order details based on a specific timeslot ('15:00 to 16:00')
$check_d = DB::table('orders')->where([
'currentDate' => $request['booking_date'] != null ? $request['booking_date'] : date('Y-m-d'),
'location_id' => $loginDetail->location_id,
'timeslot' => $request['timeslot']
])->pluck('id')->toArray();

$times = explode(" to ", $request['timeslot']);

$start_time = strtotime($times[0]); // Convert start time to timestamp
$end_time = strtotime($times[1]);   // Convert end time to timestamp

$time_difference = $end_time - $start_time; // Calculate time difference in seconds

// If you want to convert the time difference to a specific unit (e.g., minutes)
$time_difference_minutes = $time_difference / 60;
$time_difference_hr = $time_difference_minutes / 60;
//dd($time_difference_hr);
// Count the total quantity in the cart
foreach ($cart as $c) {
$cn += $c->quantity;
}
$or_q = DB::table('order_details')->whereIn('order_id', $check_d)->pluck('qty')->toArray();
$cd = 0;
foreach ($or_q as $cs) {
$cd += (int)$cs; // $cs already represents a single quantity value
}
//dd($cd + $cn <= $time_difference_hr *(int)$location->tickestperhr);
if( $cn <= 20){
if($cd + $cn <= $time_difference_hr *(int)$location->tickestperhr){
if (!$check_lo->isEmpty()) {
// Check if available seats are greater than or equal to the number of order details
if ($time_difference_hr * (int)$location->tickestperhr >= $cd) {
  // $sequence[] = count($check_lo) + 1 ;
 // Assign sequence numbers based on the count of order details
 // for ($i = 1; $i <= $cn; $i++) {
     // $sequence[] = $cd + $i; 
 // } 
} else {
 $errorMessage= 'current slot seat is full';
 // Toastr::error(\App\CPU\translate('current slot seat is full'));
 // return back();
}
} else {
// $sequence[] = count($check_lo) + 1 ;
 
}
}else {
 $errorMessage='Only ' . ($time_difference_hr * (int)$location->tickestperhr - $cd  ) . ' tickets are now available in this slot.';
 // Toastr::error('Only ' . ($time_difference_hr * (int)$location->tickestperhr - $cd  ) . ' tickets are now available in this slot.');
 // return back();
}
}
else{
$errorMessage='Maximum 20 tickets are allowed';
// Toastr::error(\App\CPU\translate('Maximum 20 tickets are allowed'));
 // return back();
}
return $errorMessage;
   }
public function edc_cancel(Request $request) {
    $data = Helpers::get_seller_by_token($request);
 
    if ($data['success'] == 1) {
        $seller = $data['data'];
    //dd($request->p2pRequestId);
//	$seller = auth('seller')->user();
$shop = DB::table('shops')->where('seller_id', $seller->id)->first();

$curl = curl_init();

$data = array(
    "username" => $seller->device_username,
    "appKey" => "39d20162-38c8-451b-bbe2-4c8251432876",
    "origP2pRequestId" => $request->p2pRequestId, // Assuming $request is initialized somewhere in your code
    "pushTo" => array("deviceId" => $seller->device_id)
);

curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://www.ezetap.com/api/3.0/p2padapter/cancel',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json'
    ),
));

$response = curl_exec($curl);

curl_close($curl);

    $responseArray = json_decode($response, true);
	//dd($responseArray);
	if($responseArray['success'] ===true && $responseArray['p2pRequestId'] != null){
		 return response()->json([
        'success' => $responseArray['success'],
        'message' => $responseArray['message'],
		'p2pRequestId' => $responseArray['p2pRequestId'],
		]);	
	}else{
		return response()->json([
        'success' => $responseArray['success'],
        'message' => $responseArray['message'],
		]);	
	}
} else {
    return response()->json(
        [
            'auth-001' => translate('Your existing session token does not authorize you anymore'),
        ],
        401
    );
} 
}
	public function edc_payUpi(Request $request) {
        
        $data = Helpers::get_seller_by_token($request);
        if ($data['success'] == 1) {
            $seller = $data['data'];

        $d = $this->checktkt($request->all(),$seller);
        if($d != null){
            return response()->json([
            'success' => false,
            'message' => $d,
            ]);	
        }
    $shop = DB::table('shops')->where('seller_id', $seller->id)->first();
	 $loginDetail = DB::table('login_details')
            ->where('vendor_id', $seller->id)
            ->whereNull('logout_time') 
            ->latest() 
            ->first();
    $location = DB::table('locations')
            ->where('id', $loginDetail->location_id)
            ->first();
            $checkcredentials = DB::table('payment_credentials')->where('location_id',$loginDetail->location_id)->first();
            $order_id = 100000 + Order::all()->count() + 1;

            if (Order::find($order_id)) {
                $order_id = Order::orderBy('id', 'DESC')->first()->id + 1;
            }  
            
           // $externalRefNumber = 'payupiedc_'.$order_id; 
           $externalRefNumber = 'payupiedc_' . $loginDetail->location_id . '_' . uniqid();
    //$externalRefNumber = 'payupiedc_'.rand(1000000,9999999);
    // Initialize cURL
    $curl = curl_init();
    // Set cURL options for payment request
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://www.ezetap.com/api/3.0/p2padapter/pay',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode(array(
            "appKey" => $checkcredentials->app_key,
            //"appKey" => "39d20162-38c8-451b-bbe2-4c8251432876",
          //  "username" => "8087321321",
		    "username" =>  $seller->device_username,
            "amount" => $request->amount,  
            "customerMobileNumber" => $request->customerMobileNumber,
            "externalRefNumber" =>  $externalRefNumber,
            "externalRefNumber2" => "",
            "externalRefNumber3" => "",
            "accountLabel" => "",
            "customerEmail" => $request->customerEmail,
            "pushTo" => array(
                //"deviceId" => "1493812219|ezetap_android"
				"deviceId" => $seller->device_id
            ),
           // "mode" => "BHARATQR"
            "mode" => "UPI"
        )),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json'
        ),
    ));

    // Execute payment cURL request
    $response = curl_exec($curl);

    // Close cURL connection for payment request
    curl_close($curl);
//return $response;
    // Decode JSON response for payment request
    $responseArray = json_decode($response, true);
	if($responseArray['success'] ===true && $responseArray['p2pRequestId'] != null){
		 return response()->json([
        'success' => $responseArray['success'],
        'message' => $responseArray['message'],
		'p2pRequestId' => $responseArray['p2pRequestId'],
        'externalRefNumber' => $externalRefNumber
		]);	
	}else{
		return response()->json([
        'success' => $responseArray['success'],
        'message' => $responseArray['message'],
		]);	
	}
} else {
    return response()->json(
        [
            'auth-001' => translate('Your existing session token does not authorize you anymore'),
        ],
        401
    );
} 
}
  
public function edcCard_pay(Request $request) { 
    $data = Helpers::get_seller_by_token($request);
    
    if ($data['success'] == 1) {
        $seller = $data['data'];
        //dd($seller->id);  
    $d = $this->checktkt($request->all(),$seller);
	if($d != null){
		return response()->json([
        'success' => false,
        'message' => $d,
		]);	
	}
	// $seller = auth('seller')->user();
	 $shop = DB::table('shops')->where('seller_id', $seller->id)->first();

	 $loginDetail = DB::table('login_details')
            ->where('vendor_id', $seller->id)
            ->whereNull('logout_time') // Select the record where logout time is null (i.e., user is currently logged in)
            ->latest() // If multiple login sessions are present, select the latest one
            ->first();
    $location = DB::table('locations')
            ->where('id', $loginDetail->location_id)
            ->first();
            $checkcredentials = DB::table('payment_credentials')->where('location_id',$loginDetail->location_id)->first();
      $order_id = 100000 + Order::all()->count() + 1;

            if (Order::find($order_id)) {
                $order_id = Order::orderBy('id', 'DESC')->first()->id + 1;
            }
             
          //  $externalRefNumber = 'paycardedc_'.$order_id;
          $externalRefNumber = 'paycardedc_' . $loginDetail->location_id . '_' . uniqid();
    //$externalRefNumber = 'paycardedc_'.rand(1000000,9999999);
    $curl = curl_init();

    // Set cURL options for payment request
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://www.ezetap.com/api/3.0/p2padapter/pay',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode(array(
            "appKey" => $checkcredentials->app_key,
          //  "appKey" => "39d20162-38c8-451b-bbe2-4c8251432876",
          //  "username" => "8087321321",
		    "username" =>  $seller->device_username,
            "amount" => $request->amount,  
            "customerMobileNumber" => $request->customerMobileNumber,
            "externalRefNumber" => $externalRefNumber,
            "externalRefNumber2" => "",
            "externalRefNumber3" => "",
            "accountLabel" => "",
            "customerEmail" => $request->customerEmail,
            "pushTo" => array(
                //"deviceId" => "1493812219|ezetap_android"
				"deviceId" => $seller->device_id
            ),
            "mode" => "CARD"
        )),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json'
        ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);
    $responseArray = json_decode($response, true);
	if($responseArray['success'] ===true && $responseArray['p2pRequestId'] != null){
		 return response()->json([
        'success' => $responseArray['success'],
        'message' => $responseArray['message'],
		'p2pRequestId' => $responseArray['p2pRequestId'],
        'externalRefNumber'=> $externalRefNumber
		]);	
	}else{
		return response()->json([
        'success' => $responseArray['success'],
        'message' => $responseArray['message'],
		]);	
	}
} else {
    return response()->json(
        [
            'auth-001' => translate('Your existing session token does not authorize you anymore'),
        ],
        401
    );
} 
}

   
public function edc_status(Request $request) {
   // dd($request->all());  
    $data = Helpers::get_seller_by_token($request);

    if ($data['success'] == 1) {
        $seller = $data['data'];

	// $seller = auth('seller')->user();
	// $shop = DB::table('shops')->where('seller_id', $seller->id)->first();
    $loginDetail = DB::table('login_details')
    ->where('vendor_id', $seller->id)
    ->whereNull('logout_time') 
    ->latest()
    ->first();
 
$checkcredentials = DB::table('payment_credentials')->where('location_id',$loginDetail->location_id)->first();
	 
    $curl = curl_init();

    // Set cURL options
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://www.ezetap.com/api/3.0/p2padapter/status',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode(array(
           // "username" => "8087321321",
		  "username" =>  $seller->device_username,
          "appKey" => $checkcredentials->app_key,  
          //  "appKey" => "39d20162-38c8-451b-bbe2-4c8251432876",
            "origP2pRequestId" => $request->p2pRequestId
        )),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json'
        ),
    ));

    // Execute cURL request
    $response = curl_exec($curl);

    // Close cURL connection
    curl_close($curl);
   // dd($response);
    $responseArray = json_decode($response, true);
	return $responseArray;
} else {
    return response()->json(
        [
            'auth-001' => translate('Your existing session token does not authorize you anymore'),
        ],
        401
    );
}
    // Return the response as a JSON response
  //  return response()->json($response);
}

    public function pos_info(Request $request)
    {  
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];

            $loginDetail = DB::table('login_details')
                ->where('vendor_id', $seller->id)
                ->whereNull('logout_time')
                ->latest()
                ->first(); 

            date_default_timezone_set('Asia/Kolkata');

            $category = $request->query('category_id', 0);

            $keyword = $request->query('search', false);

            $categoriesa = DB::table('time_slots')
                ->select('id','location_id','timeslot')
                ->where('location_id', $loginDetail->location_id)

                ->get()
 
                ->toArray();

                usort($categoriesa, function($a, $b) {
                    $start_time_a = strtotime(explode(" to ", $a->timeslot)[0]);
                    $start_time_b = strtotime(explode(" to ", $b->timeslot)[0]);
                    return $start_time_a - $start_time_b;
                });
  
            $current_date = date('Y-m-d');

            $is_today = $keyword === false || $keyword == $current_date;
   
            $current_time_slots = array_filter($categoriesa, function ($slot) use ($current_date, $keyword, $is_today) {
                $times = explode(" to ", $slot->timeslot);
                $start_time = $times[0]; 
                $end_time = $times[1];
            
                if ($is_today) {
                    $current_time = date("H:i");
                    return ($start_time > $current_time || $end_time > $current_time);
                } else {
                    return true;
                }
            });
            
          
            $categories = $is_today ? array_values($current_time_slots) : $categoriesa;
            

            $shop = Shop::select('id','name','address','contact','image')->where(['seller_id' => $seller['id']])->first();

            $sellerdetail = Seller::where('id', $seller['id'])->first();

            $location_id = $loginDetail->location_id;

            $location = Location::select('id','name','gst','address','cgst','sgst')->where('id', $location_id)->first();

            $key = explode(' ', $keyword);
            $tickets = [];
            if (count($categories) > 0) {
               

                if ($loginDetail && $loginDetail->location_id) {
                    $terminalCondition = ($loginDetail->terminal == 'lower_terminal') ? 'two way' : 'one way';
                   
                    $all_tickets = DB::table('ticket_categories')
                        ->join('ticket_category_lists', 'ticket_category_lists.id', '=', 'ticket_categories.category_id')
                        ->select(
                            'ticket_categories.id',
                            'ticket_categories.location_id',
                            'ticket_categories.status',
                            'ticket_categories.scanCount',
                            'ticket_categories.type',
                            'ticket_categories.price',
                            'ticket_categories.monthly_pass',
                            'ticket_category_lists.name' 
                        )
                        ->where('ticket_categories.location_id', $loginDetail->location_id)
                        ->where('ticket_categories.status', '!=', 'false')
                        ->where('ticket_categories.type', $terminalCondition)
                        ->where(function ($query) use ($seller) {
							$query->where('ticket_categories.monthly_pass', '=', $seller->monthly_pass)
								  ->orWhereNull('ticket_categories.monthly_pass');
						})
                        ->orderBy('ticket_categories.cate_type')
                        ->get();
						
						 $bothtkt = DB::table('ticket_categories')
                        ->join('ticket_category_lists', 'ticket_category_lists.id', '=', 'ticket_categories.category_id')
                        ->select(
                            'ticket_categories.id',
                            'ticket_categories.location_id',
                            'ticket_categories.status',
                            'ticket_categories.scanCount',
                            'ticket_categories.type',
                            'ticket_categories.price',
                            'ticket_categories.monthly_pass',
                            'ticket_category_lists.name'
                        )
                        ->where('ticket_categories.location_id', $loginDetail->location_id)
                        ->where('ticket_categories.status', '!=', 'false')
                        ->where('ticket_categories.type', 'two way')
                        ->where(function ($query) use ($seller) {
							$query->where('ticket_categories.monthly_pass', '=', $seller->monthly_pass)
								  ->orWhereNull('ticket_categories.monthly_pass');
						})
                        ->orderBy('ticket_categories.cate_type')
                        ->get();
						 
						$tickets = count($all_tickets) > 0  ? $all_tickets : $bothtkt;
						//dd($all_tickets,$tickets, $bothtkt);  
                }
            }

            $cart_id = 'wc-' . rand(10, 1000);

            if (!session()->has('current_user')) {
                session()->put('current_user', $cart_id);
            }

            if (!session()->has('cart_name')) {
                if (!in_array($cart_id, session('cart_name') ?? [])) {
                    session()->push('cart_name', $cart_id);
                }
            }
            $response = [
                'current_time_slots' => $categories,

                'tickets' => $tickets,

                'keyword' => $keyword,

                'category' => $category,

                'shop' => $shop,

                'location' => $location,
            ];
        } else {
            return response()->json(
                [
                    'auth-001' => translate('Your existing session token does not authorize you any more'),
                ],
                401,
            );
        }

        return response()->json($response, 200);
    }
    public function add_to_cart1(Request $request)
    {
        $data = Helpers::get_seller_by_token($request);
   $cartIds = explode(",", $request->cartId_arr);
        if ($data['success'] == 1) {
            $seller = $data['data'];
// $cart = DB::table('carts')->whereIn('id', $cartIds)->first();

$cart = DB::table('carts')->whereIn('id', $cartIds)->first();
//dd($cart);
            $tickets = DB::table('ticket_categories')
                ->join('ticket_category_lists', 'ticket_category_lists.id', '=', 'ticket_categories.category_id')
                ->where('ticket_categories.id', $request->ticket_id)
                ->first();
//dd( $tickets); 
            if (!$tickets) {
                return response()->json(
                    ['error' => 'Ticket not found'], 
                    404
                );
            }
           // dd($cart->monthly_pass,$cartIds);  
			 if ($cart === null) {
				    $cart = new Cart();
					$cart->product_id = $request->ticket_id;
					$cart->quantity = $request->quantity;
					$cart->price = $tickets->price * $request->quantity;
					$cart->name = $tickets->name;
					$cart->type = $tickets->type;
					$cart->location_id = $tickets->location_id;
					 $cart->monthly_pass = $tickets->monthly_pass;
					
					$check = $cart->save();
					$response = [
							'message' => 'Ticket has been added to your cart!',
							'status' => true,
							'inserted_id' => $cart->id
						];
			 }else if($cart->monthly_pass === $tickets->monthly_pass){
				    $cart = new Cart();
					$cart->product_id = $request->ticket_id;
					$cart->quantity = $request->quantity;
					$cart->price = $tickets->price * $request->quantity;
					$cart->name = $tickets->name;
					$cart->type = $tickets->type;
					$cart->location_id = $tickets->location_id;
					 $cart->monthly_pass = $tickets->monthly_pass;
					
					$check = $cart->save();
					$response = [
							'message' => 'Ticket has been added to your cart!',
							'status' => true,
							'inserted_id' => $cart->id
						];

			  }else{
				   $response = [
                    'message' => 'Tickets have different categories!',
                    'status' => false,
                ];
			  }
			  
		
    
             return response()->json($response, 200);
			
        } else {
            return response()->json(
                [
                    'auth-001' => translate('Your existing session token does not authorize you anymore'),
                ],
                401
            );
        }
    }

    public function add_to_cart(Request $request)
    {
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];

            $tickets = DB::table('ticket_categories')
                ->join('ticket_category_lists', 'ticket_category_lists.id', '=', 'ticket_categories.category_id')
                ->where('ticket_categories.id', $request->ticket_id)
                ->first();

            if (!$tickets) {
                return response()->json(
                    ['error' => 'Ticket not found'],
                    404
                );
            }

            

            $cart = new Cart();
            $cart->product_id = $request->ticket_id;
            $cart->quantity = $request->quantity;
            $cart->price = $tickets->price * $request->quantity;
            $cart->name = $tickets->name;
            $cart->type = $tickets->type;
            $cart->location_id = $tickets->location_id;
            $check = $cart->save();

            if ($check) {
                $response = [
                    'message' => 'Ticket has been added to your cart!',
                    'status' => true,
                    'inserted_id' => $cart->id
                ];
            } else {
                $response = [
                    'message' => 'Something went wrong while adding to cart!',
                    'status' => false,
                ];
            }

            return response()->json($response, 200);
        } else {
            return response()->json(
                [
                    'auth-001' => translate('Your existing session token does not authorize you anymore'),
                ],
                401
            );
        }
    }

    public function email_validations(Request $request)
    {
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];

        $check = DB::table('users')->where('email',$request->email)->first();
        if($check){
            $response = [
                'message' => 'email id is already exist!',
                'status' => false,
            ];
        }else{
            $response = [
                'message' => 'email id is  not exist!',
                'status' => true,
            ]; 
        }
        return response()->json($response, 200);
    } else {
        return response()->json(
            [
                'auth-001' => translate('Your existing session token does not authorize you anymore'),
            ],
            401
        );
    }

    } 
public function dashboard_info(Request $request){
    $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];
            date_default_timezone_set('Asia/Kolkata');
            $vendorId = $seller->id;
          
            $loginDetail = DB::table('login_details')
            ->where(['vendor_id'=> $seller->id,'loginStatus' => true])
           ->whereNull('logout_time') 
           ->latest() 
            ->first(); 
            $location = DB::table('locations')->where('id',$loginDetail->location_id)->first();
            
            $currentDate = date('Y-m-d');
            $currentLoginTime = $loginDetail->login_time;
            $totaltkt = DB::table('orders')
            //->join('orders', 'order_details.order_id', '=', 'orders.id')
            ->where('seller_id', $vendorId)
            ->whereDate('created_at', $currentDate)
            ->whereTime('created_at', '>', $currentLoginTime)
            ->get();
            $totalQuantity = DB::table('order_details')
            ->join('orders', 'order_details.order_format', '=', 'orders.order_format')
            ->where('orders.seller_id', $vendorId)
           // ->whereDate('order_details.created_at', $currentDate)
            ->whereDate('order_details.created_at', $currentDate)
             ->whereTime('order_details.created_at', '>', $currentLoginTime)
            ->sum('qty');

            $roundSign = DB::table('orders')
            ->where('seller_id', $vendorId)
            ->whereDate('created_at', $currentDate)
            ->whereTime('created_at', '>', $currentLoginTime)
            ->sum('roundSign'); 
    //dd($totaltkt);   
    $totalOrdercount = DB::table('orders')
    //->join('orders', 'order_details.order_id', '=', 'orders.id')
    ->where('seller_id', $vendorId)
    ->whereDate('created_at', $currentDate)
    ->whereTime('created_at', '>', $currentLoginTime)
    ->count();

    $totaltkt = DB::table('orders')
    //->join('orders', 'order_details.order_id', '=', 'orders.id')
    ->where('seller_id', $vendorId)
    ->whereDate('created_at', $currentDate)
    ->whereTime('created_at', '>', $currentLoginTime)
    ->get();

    $tickets_detail = DB::table('order_details')
    ->join('orders', 'order_details.order_format', '=', 'orders.order_format')
    ->where('orders.seller_id', $vendorId)
    ->whereDate('order_details.created_at', $currentDate)
    ->whereTime('order_details.created_at', '>', $currentLoginTime)
    //->min('order_details.order_format');
    ->get();

    $pd = [];
    $ttwq = 0;
    $towq = 0;
    $ttwp = [];
    $towp = [];
    $cash = [];
    $online = [];
    //dd($totaltkt);  
    foreach($totaltkt as $ticket_details){
       // dd($ticket_details);
        if (strcasecmp($ticket_details->payment_method, "cash") === 0) {
           // array_push($cash, $ticket_details->price);
           
           array_push($cash, $ticket_details->order_amount);
        } else {
           // array_push($online, $ticket_details->price);
           array_push($online, $ticket_details->order_amount);
        }
    
    }
    
    foreach($tickets_detail as $ticket_details){
        $p_detail = json_decode($ticket_details->product_details);
        if($p_detail->type === "two way"){
            if (isset($pd[$p_detail->name])) {
                $pd[$p_detail->name]['qty'] += $ticket_details->qty;
                $pd[$p_detail->name]['total_price'] += $ticket_details->qty * $p_detail->price;
            } else {
                $pd[$p_detail->name] = [
                    'name' => $p_detail->name,
                    'qty' => $ticket_details->qty,
                    'total_price' => $ticket_details->qty * $p_detail->price
                ];
            }
            $ttwq += $ticket_details->qty;
            array_push($ttwp, $ticket_details->qty * $p_detail->price);
        } else {
            if (isset($pd[$p_detail->name])) {
                $pd[$p_detail->name]['qty'] += $ticket_details->qty;
                $pd[$p_detail->name]['total_price'] += $ticket_details->qty * $p_detail->price;
            } else {
                $pd[$p_detail->name] = [
                    'name' => $p_detail->name,
                    'qty' => $ticket_details->qty,
                    'total_price' => $ticket_details->qty * $p_detail->price
                ];
            }
            $towq += $ticket_details->qty;
            array_push($towp, $ticket_details->qty * $p_detail->price);
        }
    }

    $gross_amount = round(array_sum($ttwp + $towp), 2);
			$cgst = round($gross_amount * $location->cgst / 100, 2);
			$sgst = round($gross_amount * $location->sgst / 100, 2);
           // $m = $roundOffMinus;
			$rupees = round($gross_amount + $cgst + $sgst , 2) + round($roundSign,2);
			 $final = 0;
           // $roundoff = 0;

            $whole = floor($rupees);      // Get the whole part
            $fraction = bcsub($rupees, $whole, 2); // Get the fractional part
            // Calculate the remaining fraction to reach 100
            $remaining_v = bcsub(100, $fraction, 2);

            $wholer = floor($remaining_v);      // Get the whole part of the remaining fraction
            $fractionr = bcsub($remaining_v, $wholer, 2); // Get the fractional part of the remaining fraction

            // Convert fractionr to float
            $floatValue = floatval($fractionr);
            // echo $fraction .' /'; // Outputs: 1/2 /
            // echo round($roundSign,2).' /'; // Outputs: + /
            // echo $rupees .' /';   // Outputs: 100 /
            if ($fraction >= 0.01 && $fraction <= 0.49) {
                $roundOff = $fraction;
                $final= $rupees - $fraction;

            } else if ($fraction >= 0.50 && $fraction <= 0.99) {
                $roundOff = $floatValue;
                $final= $rupees + $floatValue;

            } else {
                if ($rupees > 0) {
                    $final = $rupees ;
                }
                $roundOff = 0;
            }

    $dashboad_info=[
        'login_date_time'=> $loginDetail->login_date,  
        'total_tickets'=> $totalOrdercount,
        'total_collection'=> $final ,
        'total_orders'=> $totalQuantity,
        'collection_break'=>
        [
            'cash'=> round(array_sum($cash),2), 
            'online'=> round(array_sum($online),2),    
        ],
    ];
    return response()->json(['message' => 'dashboard record fetch Successfully! ','dashboad_info'=> $dashboad_info,'status'=> true], 200);
} else {
    return response()->json(
        [
            'auth-001' => translate('Your existing session token does not authorize you anymore'),
        ],
        401
    );
} 
}
    public function get_customer(Request $request)
    {
       

        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];

            $key = explode(' ', $request['phone']);

            $result = DB::table('users')
                ->select('users.id','users.name','users.f_name','users.l_name','users.phone','users.email','users.city','users.state','users.gst','users.gstin')
                ->where(function ($phone) use ($key) {
                    foreach ($key as $value) {
                        $phone->orWhere('phone', 'like', "{$value}%");
                    }
                })

                ->whereNotNull(['name', 'phone'])

                ->limit(8)

                ->get();

            $response = [
                'result' => $result,
            ];
        } else {
            return response()->json(
                [
                    'auth-001' => translate('Your existing session token does not authorize you any more'),
                ],
                401,
            );
        }

        return response()->json($response, 200);
    }

    public function add_customer(Request $request)
    {
        //dd($request->all());
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',

                'email' => 'required|email|regex:/(.+)@(.+)\.(.+)/i|unique:users',

                'phone' => 'required|unique:users,phone|digits:10|max:10',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

            $lastInsertedId = User::insertGetId([
                'name' => $request['name'],

                'email' => $request['email'],

                'phone' => $request['phone'],

                'is_active' => 1,

                'password' => bcrypt($request['password']),

                'state' => $request['state'],

                'city' => $request['city'],

                'gst' => $request['gst'],

                'gstin' => $request['gstin'],
            ]);

            $response = [
                'message' => 'Customer added successfully.',

                'last_inserted_id' => $lastInsertedId,
            ];
        } else {
            return response()->json(
                [
                    'auth-001' => translate('Your existing session token does not authorize you any more'),
                ],
                401,
            );
        }

        return response()->json($response, 200);
    }

    public function get_cart(Request $request)
    {
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];

            if ($request->has('cartId_arr')) {
                $cartIds = explode(",", $request->cartId_arr);


                $result = DB::table('carts')
                    ->select('carts.id as carts_id', 'carts.id','carts.product_id', 'carts.quantity','carts.price','carts.name','carts.location_id','carts.timeslot','carts.booking_date','carts.type','carts.sequence','locations.gst as locations_gst','locations.address as locations_address','locations.cgst','locations.sgst','locations.id as locations_id')
                    ->join('locations', 'carts.location_id', '=', 'locations.id')
                    ->whereIn('carts.id', $cartIds)
                    ->get();

                date_default_timezone_set('Asia/Kolkata');
                $loginDetail = DB::table('login_details')
                    ->where('vendor_id', $seller->id)
                    ->whereNull('logout_time') // Select the record where logout time is null (i.e., user is currently logged in)
                    ->latest() // If multiple login sessions are present, select the latest one
                    ->first();

                $location = DB::table('locations')->where('id', $loginDetail->location_id)->first();

                if ($result->isNotEmpty()) {

                    $total_ticket = 0;
                    $total = 0;
                    $one_wayc= 0;
                    $two_wayc= 0;
                    foreach($result as $results){

                        if($results->type == 'one way'){
                            $one_wayc +=$results->quantity;
                        }else if($results->type == 'two way'){
                            $two_wayc +=$results->quantity;
                        }
                        $total += $results->price;
                        $total_ticket += $results->quantity;

                    }
                    $cgst = round($total * $location->cgst / 100, 2);
                    $sgst = round($total *  $location->sgst / 100, 2);

                    $rupees = round($total + $cgst + $sgst, 2);
                    $final = 0;
                    $roundoff = 0;

                    $whole = floor($rupees);
                    $fraction = bcsub($rupees, $whole, 2);
                    $remaining_v = bcsub(100, $fraction, 2);

                    $wholer = floor($remaining_v);
                    $fractionr = bcsub($remaining_v, $wholer, 2);

                    $floatValue = floatval($fractionr);

                    if ($fraction >= 0.01 && $fraction <= 0.49) {
                        $roundoff = $fraction;
                        $final= $rupees - $fraction;

                    } else if ($fraction >= 0.50 && $fraction <= 0.99) {
                        $roundoff = $floatValue;
                        $final= $rupees + $floatValue;

                    } else {
                        if ($rupees > 0) {
                            $final = $rupees ;
                        }
                    }


                    $response = [
                        'status' => 'success',
                        'message' => 'Cart retrieved successfully',
                        'result' => $result,
                        'total_ticket' => $total_ticket,
                        'oneway_count' => $one_wayc,
                        'twoway_count' => $two_wayc,
                        'item_price' =>number_format($total, 2, '.', ''),
                        'cgst' => $cgst,
                        'sgst' => $sgst,
                        'roundoff'=> $roundoff,
                        'total_amount' => number_format($final, 2, '.', ''),
                    ];
                } else {
                    $response = [
                        'status' => 'error',
                        'message' => 'Cart is empty',
                    ];
                    return response()->json($response, 400);
                }
            } else {
                $response = [
                    'status' => 'error',
                    'message' => 'cartId_arr parameter is required',
                ];
                return response()->json($response, 400);
            }
        } else {
            $response = [
                'status' => 'error',
                'message' => translate('Your existing session token does not authorize you anymore'),
            ];
            return response()->json($response, 401);
        }

        return response()->json($response, 200);
    }

    public function update_cart(Request $request)
    {
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];
            $check = DB::table('carts')->where('id', $request->cart_id)->first();

            if ($check) {
                $tickets = DB::table('ticket_categories')
                    ->join('ticket_category_lists', 'ticket_category_lists.id', '=', 'ticket_categories.category_id')
                    ->select('ticket_categories.id', 'ticket_categories.scanCount', 'ticket_categories.location_id', 'ticket_categories.price', 'ticket_category_lists.name')
                    ->where('ticket_categories.id', $check->product_id)
                    ->first();

                if ($tickets) {
                    DB::table('carts')
                        ->where('id', $request->cart_id)
                        ->update([
                            'quantity' => $request->quantity,
                            'price' => $tickets->price * $request->quantity,
                        ]);

                    $response = [
                        'message' => 'Record updated successfully',
                        'status' => true
                    ];
                } else {
                    $response = [
                        'error' => 'Ticket not found',
                        'status' => false
                    ];
                }
            } else {
                $response = [
                    'error' => 'Cart item not found',
                    'status' => false
                ];
            }
        } else {
            $response = [
                'auth-001' => translate('Your existing session token does not authorize you anymore'),
                'status' => false
            ];
        }

        return response()->json($response, $response['status'] ? 200 : 404);
    }




    public function delete_cart(Request $request)
    {

        $data = Helpers::get_seller_by_token($request);


        if ($data['success'] == 1) {
            $seller = $data['data'];

            $deleted = DB::table('carts')->where('id', $request->cart_id)->delete();

            if ($deleted) {
                $response = [
                    'message' => 'Record deleted successfully',
                    'status' => true,
                ];
                $status = 200;
            } else {
                $response = [
                    'error' => 'Cart item not found or could not be deleted',
                    'status' => false,
                ];
                $status = 404;
            }
        } else {
            $response = [
                'error' => 'Your existing session token does not authorize you anymore',
                'status' => false,
            ];
            $status = 401;
        }

        return response()->json($response, $status);
    }

 


    public function location_info(Reqquest $request)
    {

        $location = Location::orderBy('id', 'Desc')->get();
        $terminal = ['lower_terminal', 'upper_terminal'];

        $response = [
            'message' => 'Location Fetch Successfully.',

            'Location' => $location,
            'Terminal' => $terminal,
        ];
    }

public function formatNumber($number) {
    return str_pad(substr((string)$number, -4), 4, "0", STR_PAD_LEFT);
}
public function place_order1(Request $request)
    {  
        
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
                    $seller = $data['data'];

                    $cartIds = explode(",", $request->cartId_arr);
                    $cart = DB::table('carts')->whereIn('id', $cartIds)->get();
                    date_default_timezone_set('Asia/Kolkata');
                    $loginDetail = DB::table('login_details')
                ->where('vendor_id', $seller->id)
                ->whereNull('logout_time')
                ->latest()
                ->first();
               // dd($loginDetail->terminal);   
                $substation = $loginDetail->terminal;
             
               // dd($request->all(),$cart,$loginDetail);
            if ($cart->isNotEmpty()) {
                $sequence = 0;
                $location = DB::table('locations')->where('id', $loginDetail->location_id)->first();
                $check_lo = DB::table('orders')->where([
                    'booking_date' => $request->booking_date != null ? $request->booking_date : date('Y-m-d'),
                    'location_id' => $loginDetail->location_id,
                    'timeslot' => $request->timeslot
                ])->get();

                $orderDetails = Order::where([
                  //  'seller_id' => $seller->id,
                  'location_id' => $loginDetail->location_id,
                    'timeslot' => $request->timeslot,
                    'booking_date' => $request->booking_date != null ? $request->booking_date : date('Y-m-d'),
                ])->get();

              
                $cn = 0;
 
                $check_d = DB::table('orders')->where([
                    'booking_date' => $request->booking_date != null ? $request->booking_date : date('Y-m-d'),
                    'location_id' => $loginDetail->location_id,
                    'timeslot' =>$request->timeslot
                ])->pluck('id')->toArray();

                $times = explode(" to ", $request->timeslot);
//dd($times);
                $start_time = strtotime($times[0]);
                $end_time = strtotime($times[1]);

                $time_difference = $end_time - $start_time;

                $time_difference_minutes = $time_difference / 60;
                $time_difference_hr = $time_difference_minutes / 60;

 $itemcPrices = DB::table('carts')->whereIn('id', $cartIds)->sum('price');
                $order_details = [];
                $product_price = 0;
                $total_tickets = 0;
				

                foreach ($cart as $c) {
                    $cn += $c->quantity;
					//array_push($itemcPrices, $c->price);
					$total_tickets += $c->quantity;
					
                        $product = DB::table('ticket_categories')
                        ->join('ticket_category_lists', 'ticket_category_lists.id', '=', 'ticket_categories.category_id')
                        ->select('ticket_categories.id', 'ticket_categories.valid_days','ticket_categories.monthly_pass', 'ticket_categories.scanCount', 'ticket_categories.type', 'ticket_categories.location_id', 'ticket_categories.price', 'ticket_category_lists.name')
                        ->where('ticket_categories.id', $c->product_id)
                        ->first(); 
                        $validD = $product->valid_days ? $product->valid_days - 1 : 0;
                        $dateTime = new \DateTime();
                        $dateTime->modify("+$validD days");
                        $monthly_valid_date = $dateTime->format('d-m-Y');
                }
               // dd($itemcPrices);
                $or_q = DB::table('order_details')->whereIn('order_id', $check_d)->pluck('qty')->toArray();
                $cd = 0;

                foreach ($or_q as $cs) {
                    $cd += (int)$cs;
                }

                if( $cn <= 20 ){
                    if($cd + $cn <= $time_difference_hr *(int)$location->tickestperhr){
                        if (!$check_lo->isEmpty()) {
                            if ($time_difference_hr * (int)$location->tickestperhr >= $cd) {
                                $sequence = $orderDetails->count() + 1;
                                // for ($i = 1; $i <= $cn; $i++) {
                                //     $sequence[] = $cd + $i;
                                // }
                            } else {
									return	 $response = [
										'message' => 'current slot seat is full',
										'status' => false
									];
                              
                            }
                        } else {
                            $sequence =1;
                            // for ($i = 1; $i <= $cn; $i++) {
                            //     $sequence[] = $i; // Add $i to the $sequence array
                            // }
                        }
                    }else {
						return	 $response = [
                    'message' => 'Only ' . ($time_difference_hr * (int)$location->tickestperhr - $cd) . ' tickets are now available in this slot.',
                    'status' => false
                ];
                        // Toastr::error('Only ' . ($time_difference_hr * (int)$location->tickestperhr - $cd) . ' tickets are now available in this slot.');
                        // return back();
                    }
                }else{
				return	 $response = [
                    'message' => 'Maximum 20 tickets are allowed',
                    'status' => false
                ];
                    // Toastr::error('Maximum 20 tickets are allowed');
                    // return back();
                }
                if ($request->amount === '' || $request->amount === null) {
                    return response()->json([
                        'message' => 'Order total amount is zero!',
                        'status' => false
                    ]);
                }
                if(!$request->customer_id){

                    $validator = Validator::make($request->all(), [
                        'name' => 'required|string|max:255',

                         'email' => 'email|regex:/(.+)@(.+)\.(.+)/i|unique:users',

                        'phone' => 'required|unique:users,phone|digits:10|max:10',
                    ]);

                    if ($validator->fails()) {
                        return response()->json(['errors' => Helpers::error_processor($validator)], 403);
                    }

                    $lastInsertedId = User::insertGetId([
                        'name' => $request['name'],

                        'email' => $request['email'],

                        'phone' => $request['phone'],

                        'is_active' => 1,

                        'password' => bcrypt($request['password']),

                        'state' => $request['state'],

                        'city' => $request['city'],

                        'gst' => $request['gst'],

                        'gstin' => $request['gstin'],
                    ]);
                }
                $user_id = $request->customer_id != null ? $request->customer_id : $lastInsertedId;
                $user_email = $request->customer_id != null ? DB::table('users')->where('id', $request->customer_id)->first()->email : $request->email;
                $shop = Shop::where('seller_id', $seller->id)->first();

                              //dd($seller->location_id);
                $location = Location::find($loginDetail->location_id);
                //dd( $location);
                $gst = $location->cgst + $location->sgst;

              
                // $order_id = 100000 + Order::count() + 1;

                // if (Order::find($order_id)) {
                    // $order_id = Order::orderBy('id', 'DESC')->first()->id + 1;
                // }
               
				// Example usage
                        $selleId = (int)$shop->seller_id;
				 
                $itemPrice = round($itemcPrices,2);
                $tcgst  = round($itemPrice * $location->cgst / 100,2);
                $tsgst = round($itemPrice * $location->sgst / 100,2);
                        
                        
                $rupees = round($itemPrice + $tcgst + $tsgst, 2);
                    $whole = floor($rupees); // Get the whole part
                    $fraction = bcsub($rupees, $whole, 2); // Get the fractional part
                    // Calculate the remaining fraction to reach 100
                    $remaining_v = bcsub(100, $fraction, 2);

                   
                    $sd =	round(round($rupees),2)	;
                    $roundSign = round($sd - $rupees,2);
                    $wholer = floor($remaining_v); // Get the whole part of the remaining fraction
                    $fractionr = bcsub($remaining_v, $wholer, 2); // Get the fractional part of the remaining fraction
                    // Convert fractionr to float
                    $roundOffPlus =0;
                   // $roundOffMinus =0;
                    $floatValue = floatval($fractionr);
                    $roundOff = 0.00;
                    if ($fraction >= 0.01 && $fraction <= 0.49) {
                     $roundOff = $fraction;
                    }else if ($fraction >= 0.50 && $fraction <= 0.99) {
                        $roundOff = $floatValue;
                    }
                    $ttlamout = $rupees+$roundOff;


                        $locationId = $seller->id;
                       
                       // $currentuserlocation = json_decode(auth('seller')->user()->location_id);
                       $currentuserlocation = $loginDetail->location_id;
                       // $funalcurrentlogin = $currentuserlocation[0]; 
                
                        $now = Carbon::now();
                        $financialYearStart = Carbon::create($now->year, 6, 1, 0, 0, 0);
                        $financialYear = $now->gte($financialYearStart) ? $now->year + 1 : $now->year;
                        // dd($financialYear);
                        $finalfinancialYear = substr($financialYear, -2);
                
                       
                           
                        

                        $lastOrder = Order::where('seller_id', $locationId)
                        ->where('location_id', $currentuserlocation)
                        ->where('order_format', 'like', '%' .strtoupper(substr($location->name, 0, 3) . $this->formatNumber($selleId)). $finalfinancialYear . '%')
                      //  ->where('order_format', 'like', '%' .strtoupper(substr($location->name, 0, 3) . substr($shop->name, 0, 3)). $finalfinancialYear . '100'.'%')

                        ->where(function ($query) use ($financialYear) {
                            $query->whereYear('created_at', $financialYear)->orWhere(function ($query) use ($financialYear) {
                                $query->whereYear('created_at', $financialYear - 1)->whereMonth('created_at', '>=', 6);
                            });
                        })
                        ->orderBy('created_at', 'DESC')
                        ->first();
                
                        // Determine the new order sequence number
                        $newOrderSequence = 1;
                        if ($lastOrder) {
                            // dd('okk');
                            $lastOrderSequence = (int) substr($lastOrder->order_format, -6); // Assuming last 6 digits are the sequence
                            // dd($lastOrderSequence);
                            $newOrderSequence = $lastOrderSequence + 1;
                        }
                
                        $baseNumber = 1000000;
                        $result = $baseNumber + $newOrderSequence;
                        $finalResult = str_pad($result, 7, '0', STR_PAD_LEFT);
						
					$booked_by = $location->name;
                   //$roundOffMinus=  round($ttlamout-$rupees,2);
                // Create the order record
                $order = [ 
                    //'id' => $order_id,
                    'order_format' => strtoupper(substr($location->name, 0, 3) . $this->formatNumber($selleId)) . $finalfinancialYear . $finalResult,
                    //'order_format' => strtoupper(substr($location->name, 0, 3) . substr($shop->name, 0, 3)) . $finalfinancialYear . $finalResult,
                    // 'order_format' => strtoupper(substr($location->name, 0, 3) . substr($shop->name, 0, 3)) . $order_id,
                    'customer_id' => $user_id,
                    'order_ref'=>$request->externalRefNumber,
                    'currentDate' => now()->format('Y-m-d'),
                  //  'sequence' => $sequence,
                    'location_id'=>$loginDetail->location_id,
                    'customer_type' => 'customer',
                    'payment_status' => 'paid',
                    'order_status' => 'delivered',
                    'seller_id' => $seller->id,
                    'seller_is' => 'seller',
                    'payment_method' => $request->payment_type,
                    'timeslot' => $request->timeslot,
                    'booking_date' => $product->monthly_pass != null ? $monthly_valid_date : $request->booking_date,
                    // 'booking_date' => $request->booking_date,
                    'booked_by' => $booked_by,
                    'order_type' => 'EDC',
                    'checked' => 1,
                    'sequence'=> $sequence,
                    'total_ticket' => $total_tickets,
                    'extra_discount' => $cart->sum('ext_discount') ?? 0,
                    'extra_discount_type' => $cart->first()->ext_discount_type ?? null,
                    'order_amount' => BackEndHelper::currency_to_usd($itemPrice+$tcgst+$tsgst+$roundSign),
                   // 'order_amount' => BackEndHelper::currency_to_usd($request->amount),
                    'discount_amount' => $cart->sum('coupon_discount') ?? 0,
                    'coupon_code' => $cart->first()->coupon_code ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'itemPrice'=> $itemPrice,
                    'cgst'=> $tcgst,
                    'sgst'=> $tsgst,
                    'roundOff'=> $roundOff,
                    'substation'=> $substation,
                    'roundSign' => $roundSign,
                    'monthly_pass' => $product->monthly_pass,
                    'locationCgstPercent' => $location->cgst,
                    'locationSgstPercent' => $location->cgst,
                  //  'roundOffMinus' => $roundOffMinus,     
                ];

                // Insert the order record
                $order_id = DB::table('orders')->insertGetId($order);
              //  dd($product); 
  

                foreach ($cart as $c) {
                    // $product = DB::table('ticket_categories')
                    //     ->join('ticket_category_lists', 'ticket_category_lists.id', '=', 'ticket_categories.category_id')
                    //     ->select('ticket_categories.id','ticket_categories.monthly_pass', 'ticket_categories.scanCount', 'ticket_categories.type', 'ticket_categories.location_id', 'ticket_categories.price', 'ticket_category_lists.name')
                    //     ->where('ticket_categories.id', $c->product_id)
                    //     ->first();
                       
                        $product = DB::table('ticket_categories')
                        ->join('ticket_category_lists', 'ticket_category_lists.id', '=', 'ticket_categories.category_id')
                        ->select('ticket_categories.id', 'ticket_categories.valid_days','ticket_categories.monthly_pass', 'ticket_categories.scanCount', 'ticket_categories.type', 'ticket_categories.location_id', 'ticket_categories.price', 'ticket_category_lists.name')
                        ->where('ticket_categories.id', $c->product_id)
                        ->first(); 
                        $validD = $product->valid_days ? $product->valid_days - 1 : 0;
                        $dateTime = new \DateTime();
                        $dateTime->modify("+$validD days");
                        $monthly_valid_date = $dateTime->format('d-m-Y');

                    if ($product) {
                        $price = $c->price;
                       // array_push($itemPrices, $c->price );
                        $total_tickets += $c->quantity;
                        $or_d = [
                            'order_id' => $order_id,
                            'order_format' => strtoupper(substr($location->name, 0, 3) . $this->formatNumber($selleId)) . $finalfinancialYear . $finalResult,
                            //'order_format' => strtoupper(substr($location->name, 0, 3) . substr($shop->name, 0, 3)) . $finalfinancialYear . $finalResult,
                            // 'order_format' => strtoupper(substr($location->name, 0, 3) . substr($shop->name, 0, 3)) . $order_id,
                            'product_id' => $c->product_id,
                            'product_details' => json_encode($product),
                            'qty' => $c->quantity,
                            'price' => $price,
                            'rate' => $product->price, 
                            'tax' => Helpers::tax_calculation($price, $gst, 'percentage') * $c->quantity,
                            'discount' => '',
                            'discount_type' => 'discount_on_product',
                            'delivery_status' => 'delivered',
                            'payment_status' => 'paid',
                            'variation' => '',
                            'variant' => '',
                            'created_at' => now(),
                            'updated_at' => now()
                        ];

                        $order_details[] = $or_d;

                        DB::table('order_details')->insert($or_d);

                        // Calculate total price
                      //  $product_price += $price * $c->quantity;
                    }
                }

                // Calculate total price after applying discounts, extra discounts, etc.
             //   $total_price = $product_price - ($cart->sum('ext_discount') ?? 0);

                // Get the booked_by information
                
               
            //    if($request->mail =='true' || $request->mail == true){ 
            //     $emailServices_smtp = Helpers::get_business_settings('mail_config');
            //     // dd($emailServices_smtp);
            //             // if ($emailServices_smtp['status'] == 0) {
            //             //     $emailServices_smtp = Helpers::get_business_settings('mail_config_sendgrid');
            //             // }
            //             if ($emailServices_smtp['status'] == 1) {
                             
            //                 Mail::to($user_email)->send(new \App\Mail\OrderPlaced($order_id));
            //                 // Mail::to($seller->email)->send(new \App\Mail\OrderReceivedNotifySeller($order_id));
            //             }
            //    }
             
               DB::table('carts')->whereIn('id', $cartIds)->delete();

                 
                //$cart = DB::table('carts')->whereIn('id', $cartIds)->delete();

                  //  dd($request->timeslot); 

                $response = [
                    'message' => 'Order placed successfully',
                    'status' => true,
                    'order_id' => $order_id
                ];
            } else {
                $response = [
                    'message' => 'Cart is empty!',
                    'status' => false
                ];
            }
        } else {
            $response = [
                'auth-001' => translate('Your existing session token does not authorize you anymore'),
                'status' => false
            ];
            return response()->json($response, 401);
        }
//dd($response);
        return response()->json($response, 200);
    }

    public function get_location(Request $request)
    {
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];
            $location = DB::table('locations')->where('id', $seller->location_id)->first();

            if ($location) {
                $response = [
                    'location' => $location,
                    'status' => true
                ];
            } else {
                $response = [
                    'location' => 'No location found',
                    'status' => false
                ];
            }
        } else {
            $response = [
                'auth-001' => translate('Your existing session token does not authorize you anymore'),
                'status' => false
            ];
            return response()->json($response, 401);
        }

        return response()->json($response, 200);
    }

    public function download_tickets(Request $request)
    {
        // Get seller data using token
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];
            $loginDetail = DB::table('login_details')
                ->where('vendor_id', $seller->id)
                ->whereNull('logout_time') // Select the record where logout time is null (i.e., user is currently logged in)
                ->latest() // If multiple login sessions are present, select the latest one
                ->first();
            //dd($loginDetail);
            date_default_timezone_set('Asia/Kolkata');
            $location = DB::table('locations')->where('id', $loginDetail->location_id)->first();
            $locationTermCondition = html_entity_decode($location->termandconditions);
            
   
              
            // Find the order by ID
            $order = \App\Model\Order::find($request->order_id);
            $bookingDate = $order->created_at->toDateString();
         // $bookingDate = $order->booking_date;
            $bookingTime = $order->created_at->format('H:i:s');
           // dd($order,$loginDetail->location_id);  
            $reporting_time =DB::table('time_slots')->where(['timeslot'=> $order->timeslot,'location_id'=>$loginDetail->location_id])->first(); 
           // dd();
            $order_detail = DB::table('order_details')->where('order_id', $request->order_id)->get();
 

            $customerdetail=DB::table('users')->where('id',$order->customer_id)->first();
            $total_ticket =0;
            $total_p = 0;
            $data = [];
            if(count($order_detail) > 0){
                foreach($order_detail as $order_details){

                    $total_p += $order_details->price;
                    $total_ticket += $order_details->qty;
                    $d = [
                        'id' => $order_details->id,
                        'order_id' => $order_details->order_id,
                        'order_format' => $order_details->order_format,
                        'product_id' => $order_details->product_id,
                        'seller_id' => $order_details->seller_id,
                        'product_details' => json_decode($order_details->product_details),
                        'qty' => $order_details->qty,
                        'price' => $order_details->price,
                        'tax' => $order_details->tax,
                        'discount' => $order_details->discount,
                        'delivery_status' => $order_details->delivery_status,
                        'created_at' => $order_details->created_at,
                        'updated_at' => $order_details->updated_at,
                        'shipping_method_id' => $order_details->shipping_method_id,
                        'variant' => $order_details->variant,
                        'variation' => $order_details->variation,
                        'discount_type' =>$order_details->discount_type,
                        'refund_request' =>$order_details->refund_request,

                    ];
                    array_push($data, $d);
                }
            }
            $gst = [
                'cgst' => round($total_p * $location->cgst / 100,2),
                'sgst' => round($total_p *  $location->sgst / 100,2),
            ];
            $rupees = round($total_p + round($total_p * $location->cgst / 100, 2) + round($total_p * $location->sgst / 100, 2), 2);

            $final = 0;
            $roundoff = 0;

            $whole = floor($rupees);      // Get the whole part
            $fraction = bcsub($rupees, $whole, 2); // Get the fractional part
            // Calculate the remaining fraction to reach 100
            $remaining_v = bcsub(100, $fraction, 2);

            $wholer = floor($remaining_v);      // Get the whole part of the remaining fraction
            $fractionr = bcsub($remaining_v, $wholer, 2); // Get the fractional part of the remaining fraction

            // Convert fractionr to float
            $floatValue = floatval($fractionr);

            if ($fraction >= 0.01 && $fraction <= 0.49) {
                $roundoff = $fraction;
                $final= $rupees - $fraction;

            } else if ($fraction >= 0.50 && $fraction <= 0.99) {
                $roundoff = $floatValue;
                $final= $rupees + $floatValue;

            } else {
                if ($rupees > 0) {
                    $final = $rupees ;
                }
            }

            if ($order) {
     
                $response = [
                    'order' => $order,
                    'bookingDate' => date('d-m-Y', strtotime($bookingDate)) ,
                    'bookingTime'=>date('H:i:s', strtotime($bookingTime)),
                    'order_details' => $data, // Add order details to the response
                    'location' => $location,
                    'locationTermCondition' => html_entity_decode( $location->termandconditions), 
                    'customer_details'=> $customerdetail,
                    'total_ticket' => $total_ticket,
                    'item_price' => number_format($total_p, 2, '.', ''),
                    'gst' => $gst,
					'roundoff' => $roundoff, 
                    'total_amount' =>number_format($final, 2, '.', ''),
                    'reporting_time' => $reporting_time != null ? $reporting_time->reporting_time : '0' .' Minutes (Before Time Slot)',
                    'status' => true
                ];

            } else {
                // Handle case when order is not found
                $response = [
                    'order_detail' => 'No order found',
                    'status' => false
                ];
            }
        } else {
            // Handle unauthorized access
            $response = [
                'error' => translate('Your existing session token does not authorize you anymore'),
                'status' => false
            ];
            return response()->json($response, 401);
        }

        // Return JSON response
        return response()->json($response, 200);
    }

    public function download_tickets1(Request $request)
    {
        // Get seller data using token
        $data = Helpers::get_seller_by_token($request);

        if ($data['success'] == 1) {
            $seller = $data['data'];
            $loginDetail = DB::table('login_details')
                ->where('vendor_id', $seller->id)
                ->whereNull('logout_time') // Select the record where logout time is null (i.e., user is currently logged in)
                ->latest() // If multiple login sessions are present, select the latest one
                ->first();
            //dd($loginDetail);
            date_default_timezone_set('Asia/Kolkata');
            $location = DB::table('locations')->where('id', $loginDetail->location_id)->first();
            $locationTermCondition = html_entity_decode($location->termandconditions);
            
   
            $order = \App\Model\Order::where('order_format', $request->order_format)->first();
  
            // Find the order by ID
            // $order = DB::table('orders')->where('order_format',$request->order_format)->first();
            dd(            $order);  
           
           // dd($order == null || $order == '');    
            // $order = \App\Model\Order::find($request->order_id);
            // $bookingDate = $order->created_at->toDateString();
         // $bookingDate = $order->booking_date;
            // $bookingTime = $order->created_at->format('H:i:s');
            if ($order != null || $order != '') {

            $bookingDate = $order->created_at->toDateString();
$bookingTime = $order->created_at->format('H:i:s');
           // dd($order,$loginDetail->location_id);  
            $reporting_time =DB::table('time_slots')->where(['timeslot'=> $order->timeslot,'location_id'=>$loginDetail->location_id])->first(); 
           // dd();
            $order_detail = DB::table('order_details')->where('order_id', $request->order_id)->get();
 

            $customerdetail=DB::table('users')->where('id',$order->customer_id)->first();
            $total_ticket =0;
            $total_p = 0;
            $data = [];
            if(count($order_detail) > 0){
                foreach($order_detail as $order_details){

                    $total_p += $order_details->price;
                    $total_ticket += $order_details->qty;
                    $d = [
                        'id' => $order_details->id,
                        'order_id' => $order_details->order_id,
                        'order_format' => $order_details->order_format,
                        'product_id' => $order_details->product_id,
                        'seller_id' => $order_details->seller_id,
                        'product_details' => json_decode($order_details->product_details),
                        'qty' => $order_details->qty,
                        'price' => $order_details->price,
                        'tax' => $order_details->tax,
                        'discount' => $order_details->discount,
                        'delivery_status' => $order_details->delivery_status,
                        'created_at' => $order_details->created_at,
                        'updated_at' => $order_details->updated_at,
                        'shipping_method_id' => $order_details->shipping_method_id,
                        'variant' => $order_details->variant,
                        'variation' => $order_details->variation,
                        'discount_type' =>$order_details->discount_type,
                        'refund_request' =>$order_details->refund_request,

                    ];
                    array_push($data, $d);
                }
            }
            $gst = [
                'cgst' => round($total_p * $location->cgst / 100,2),
                'sgst' => round($total_p *  $location->sgst / 100,2),
            ];
            $rupees = round($total_p + round($total_p * $location->cgst / 100, 2) + round($total_p * $location->sgst / 100, 2), 2);

            $final = 0;
            $roundoff = 0;

            $whole = floor($rupees);      // Get the whole part
            $fraction = bcsub($rupees, $whole, 2); // Get the fractional part
            // Calculate the remaining fraction to reach 100
            $remaining_v = bcsub(100, $fraction, 2);

            $wholer = floor($remaining_v);      // Get the whole part of the remaining fraction
            $fractionr = bcsub($remaining_v, $wholer, 2); // Get the fractional part of the remaining fraction

            // Convert fractionr to float
            $floatValue = floatval($fractionr);

            if ($fraction >= 0.01 && $fraction <= 0.49) {
                $roundoff = $fraction;
                $final= $rupees - $fraction;

            } else if ($fraction >= 0.50 && $fraction <= 0.99) {
                $roundoff = $floatValue;
                $final= $rupees + $floatValue;

            } else {
                if ($rupees > 0) {
                    $final = $rupees ;
                }
            }

           
     
                $response = [
                    'order' => $order,
                    'bookingDate' => date('d-m-Y', strtotime($bookingDate)) ,
                    'bookingTime'=>date('H:i:s', strtotime($bookingTime)),
                    'order_details' => $data, // Add order details to the response
                    'location' => $location,
                    'locationTermCondition' => html_entity_decode( $location->termandconditions), 
                    'customer_details'=> $customerdetail,
                    'total_ticket' => $total_ticket,
                    'item_price' => number_format($total_p, 2, '.', ''),
                    'gst' => $gst,
					'roundoff' => $roundoff, 
                    'total_amount' =>number_format($final, 2, '.', ''),
                    'reporting_time' => $reporting_time != null ? $reporting_time->reporting_time : '0' .' Minutes (Before Time Slot)',
                    'status' => true
                ];

            } else {
                // Handle case when order is not found
                $response = [
                    'order_detail' => 'No order found',
                    'status' => false
                ];
            }
        } else {
            // Handle unauthorized access
            $response = [
                'error' => translate('Your existing session token does not authorize you anymore'),
                'status' => false
            ];
            return response()->json($response, 401);
        }

        // Return JSON response
        return response()->json($response, 200);
    }
    public function all_orders(Request $request)
    {
        $data = Helpers::get_seller_by_token($request);
        date_default_timezone_set('Asia/Kolkata');
        
        if ($data['success'] == 1) {
            $seller = $data['data'];
    
            // Get today's date in 'Y-m-d' format
            $today = date('Y-m-d');
    
            // Retrieve orders for the seller for today
            $orders = DB::table('orders')
                ->select('orders.id as orders_id', 'orders.*', 'locations.*')
                ->join('locations', 'locations.id', '=', 'orders.location_id')
                ->where('orders.seller_id', $seller['id'])
                ->whereDate('orders.created_at', $today)
                ->orderBy('orders.id', 'DESC')
                ->get();
            
            if ($orders->isNotEmpty()) {
                $response = [
                    'orders' => $orders,
                    'status' => true
                ];
            } else {
                $response = [
                    'orders' => 'Today, no orders found.',
                    'status' => false
                ];
            }
        } else {
            $response = [
                'auth-001' => 'Your existing session token does not authorize you anymore',
                'status' => false
            ];
            return response()->json($response, 401);
        }
    
        return response()->json($response, 200);
    }
    public function getIdFromQRCode(Request $request)
    {
        $data = Helpers::get_seller_by_token($request);
        date_default_timezone_set('Asia/Kolkata');
        
        if ($data['success'] == 1) {
    
            $seller = $data['data'];
            $loginDetail = DB::table('login_details')
                ->where('vendor_id', $seller->id)
                ->whereNull('logout_time')
                ->latest()
                ->first();
                $scanUserName = $seller->f_name . ' ' . $seller->l_name;
                $locationName = DB::table('locations')->where('id', $loginDetail->location_id)->first();
                date_default_timezone_set('Asia/Kolkata');
    
                $orderDetails = OrderDetail::where('order_format', $request->order_id)->get();
                $ttkt = $orderDetails->sum('qty');
   
                $order = Order::where(['order_format'=> $request->order_id,'location_id'=>$loginDetail->location_id])->first();
             // dd($order);
                
                $d = DB::table('order_details')
                ->where('order_format', $request->order_id)   			
                ->first();
                
                $productDetails = json_decode($order->details->pluck('product_details')[0], true);
    //dd( );
                $scanCountTkt ='';  
                if (is_array($productDetails) && isset($productDetails[0])) {
                    $tkttype1 = $productDetails[0];
                    //dd($productDetails[0]);
                    $scanCountTkt = $productDetails[0]['scanCount'];
                } else {
                    $scanCountTkt = $productDetails['scanCount'];
                }
              
             if($order){
                    $validay = date('Y-m-d', strtotime($order->booking_date));
                    $currentday = date('Y-m-d', strtotime(date('d-m-Y')));
                    $scanDate1 = date('Y-m-d', strtotime($order->scanDate1));
                    $scanDate2 = date('Y-m-d', strtotime($order->scanDate2));
                // dd($order->scanCount != null && $currentday <=  $validay);   
                    if ($order->scanCount === null && $currentday <= $validay ) {
                    Order::where('order_format', $request->order_id)->update([
                        'scanCount' => 1,
                        'substation1' => str_replace('_', ' ', $loginDetail->terminal),
                        'scanDate1' => now(),
                        'scanUser1' => $scanUserName
                    ]); 
                    $response = [
                        'heading' => 'Welcome to ' . $locationName->name . ' Ropeways',
                        'tickets' => 'No of Tickets: ' . $ttkt,
                        'scanStatus' => 'first Scan: Done',
                        'stationName' => str_replace('_', ' ', $loginDetail->terminal),
                        'scanDateTime' => date('d-m-Y H:i:s'),
                        'message' => 'You are allowed to avail the service',
                        'status' => true,
                    ]; 
                    
                   
                 
                }else if($order->scanCount != null && $currentday <=  $validay){
                       // dd($scanCountTkt === "1",$scanCountTkt,$order->scanCount);   
                    if($scanCountTkt === 1) { 
                    
                        //dd($order->scanCount === 1,date('d-m-Y') ===  $order->booking_date,date('d-m-Y'),$order->booking_date); 
                        if($order->scanCount === "1"){
                            $response = [
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                'message' => 'You are allowed to avail the service', 
                                'status' => false,
                            ]; 
                        }else{
                            $response = [
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                'message' => 'ticket valid days are invalid',
                                'status' => false,
                            ]; 
                        }
                    } else if($scanCountTkt === 2) { 
                        //dd($order->scanCount === "1" ,);   
                        //dd($d->scanCount == '1',$d->scanCount,$order->scanCount,$order->scanCount === "1");
                        if($order->scanCount == "1" ){ 
                            Order::where('order_format', $request->order_id)->update([
                                'scanCount' => 2,
                                'substation2' => str_replace('_', ' ', $loginDetail->terminal),
                                'scanDate2' => date('Y-m-d H:i:s'),
                                'scanUser2' => $scanUserName
                            ]);
                            $response = [
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                'message' => 'You are  allowed to avail the service',
                                'status' => true,
                            ];
                        }else if($order->scanCount == "2" ){
                            $response = [
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                'secondScan' => [
                                    'scanDate' => $order->scanDate2,
                                    'userName' => $order->scanUser2,
                                    'stationName' => $order->substation2
                                ],
                                'message' => 'You are not allowed to avail the service',
                                'status' => false,
                            ];
                        }
                    } else if($scanCountTkt === 30) {
                        if($order->scanCount == "30" ){
                            $response = [
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                'secondScan' => [
                                    'scanDate' => $order->scanDate2,
                                    'userName' => $order->scanUser2,
                                    'stationName' => $order->substation2
                                ],
                                'message' => 'You are not allowed to avail the service',
                                'status' => false,
                            ];
                        } else {
                          if($order->scanCount < "30" ){
                             if($order->scanCount =="0"){
                                  Order::where('order_format', $request->order_id)->update([
                                    'scanCount' => 1,
                                    'substation1' => str_replace('_', ' ', $loginDetail->terminal),
                                    'scanDate1' => now(),
                                    'scanUser1' => $scanUserName
                                ]); 
                                $response = [
                                    'heading' => 'Welcome to ' . $locationName->name . ' Ropeways',
                                    'tickets' => 'No of Tickets: ' . $ttkt,
                                    'scanStatus' => 'first Scan: Done',
                                    'stationName' => str_replace('_', ' ', $loginDetail->terminal),
                                    'scanDateTime' => date('d-m-Y H:i:s'),
                                    'message' => 'You are allowed to avail the service',
                                    'status' => true,
                                ]; 
                              } else if($order->scanCount =="1"){
                                 Order::where('order_format', $request->order_id)->update([
                                'scanCount' => 2,
                                'substation2' => str_replace('_', ' ', $loginDetail->terminal),
                                'scanDate2' => date('Y-m-d H:i:s'),
                                'scanUser2' => $scanUserName
                            ]);
                            $response = [
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                'message' => 'You are  allowed to avail the service',
                                'status' => false,
                            ];
                              }else if($order->scanCount =="2" && $scanDate1 === $currentday && $scanDate2  === $currentday){
                                  Order::where('order_format', $request->order_id)->update([
                                'scanCount' => 3,
                                'substation2' => str_replace('_', ' ', $loginDetail->terminal),
                                'scanDate2' => date('Y-m-d H:i:s'),
                                'scanUser2' => $scanUserName
                            ]);
                            $response = [
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                  'SecondScan' => [
                                    'scanDate' => $order->scanDate2,
                                    'userName' => $order->scanUser2,
                                    'stationName' => $order->substation2
                                ],
                                'message' => 'today scan count done!',
                                'status' => false,
                            ];
                              }else{
                                  if( $scanDate1 === $currentday && $scanDate2  === $currentday ){  
                                     $response = [
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                  'SecondScan' => [
                                    'scanDate' => $order->scanDate2,
                                    'userName' => $order->scanUser2,
                                    'stationName' => $order->substation2
                                ],
                                'message' => 'today scan count done!',
                                'status' => false,
                            ];
                                  }else{
                                 Order::where('order_format', $request->order_id)->update([
                                'scanCount' => $order->scanCount + 1,
                                'substation2' => str_replace('_', ' ', $loginDetail->terminal),
                                'scanDate2' => date('Y-m-d H:i:s'),
                                'scanUser2' => $scanUserName
                            ]);  
                            $response = [
                             // 'heading' => 'Welcome to ' . $locationName->name . ' Ropeways',
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                'secondScan' => [
                                    'scanDate' => $order->scanDate2,
                                    'userName' => $order->scanUser2,
                                    'stationName' => $order->substation2
                                ],
                                'scanStatus' => $order->scanCount .' Scan: Done', 
                                'stationName' => str_replace('_', ' ', $loginDetail->terminal),
                                'scanDateTime' => date('d-m-Y H:i:s'),
                                'message' => 'You are allowed to avail the service',
                                'status' => true,
                            ];	  
                                  }
                                
                              }
                           
                          }
                        }
                    } else if($scanCountTkt === 60) {
                        //dd($order->scanCount < "60",$order->scanCount,$d->scanCount);
                        if($order->scanCount == "60" ){
                            $response = [
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                'secondScan' => [
                                    'scanDate' => $order->scanDate2,
                                    'userName' => $order->scanUser2,
                                    'stationName' => $order->substation2
                                ],
                                'message' => 'You are not allowed to avail the service',
                                'status' => false,
                            ];
                        } else {
                          if($order->scanCount < "60" ){
                              
                              if($order->scanCount =="0"){
                                  Order::where('order_format', $request->order_id)->update([
                                    'scanCount' => 1,
                                    'substation1' => str_replace('_', ' ', $loginDetail->terminal),
                                    'scanDate1' => now(),
                                    'scanUser1' => $scanUserName
                                ]); 
                                $response = [
                                    'heading' => 'Welcome to ' . $locationName->name . ' Ropeways',
                                    'tickets' => 'No of Tickets: ' . $ttkt,
                                    'scanStatus' => 'first Scan: Done',
                                    'stationName' => str_replace('_', ' ', $loginDetail->terminal),
                                    'scanDateTime' => date('d-m-Y H:i:s'),
                                    'message' => 'You are allowed to avail the service',
                                    'status' => true,
                                ]; 
                              } else if($order->scanCount =="1"){
                                 Order::where('order_format', $request->order_id)->update([
                                'scanCount' => 2,
                                'substation2' => str_replace('_', ' ', $loginDetail->terminal),
                                'scanDate2' => date('Y-m-d H:i:s'),
                                'scanUser2' => $scanUserName
                            ]);
                            $response = [
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                'message' => 'You are  allowed to avail the service',
                                'status' => true,
                            ];
                              }else if($order->scanCount =="2" && $scanDate1 === $currentday && $scanDate2  === $currentday){
                                  Order::where('order_format', $request->order_id)->update([
                                'scanCount' => 3,
                                'substation2' => str_replace('_', ' ', $loginDetail->terminal),
                                'scanDate2' => date('Y-m-d H:i:s'),
                                'scanUser2' => $scanUserName
                            ]); 
                            $response = [
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                  'SecondScan' => [
                                    'scanDate' => $order->scanDate2,
                                    'userName' => $order->scanUser2,
                                    'stationName' => $order->substation2
                                ],
                                'message' => 'today scan count done!',
                                'status' => false,
                            ];
                              }else{
                                  if( $scanDate1 === $currentday && $scanDate2  === $currentday ){  
                                     $response = [
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                  'SecondScan' => [
                                    'scanDate' => $order->scanDate2,
                                    'userName' => $order->scanUser2,
                                    'stationName' => $order->substation2
                                ],
                                'message' => 'today scan count done!',
                                'status' => false,
                            ];
                                  }else{
                                 Order::where('order_format', $request->order_id)->update([
                                'scanCount' => $order->scanCount + 1,
                                'substation2' => str_replace('_', ' ', $loginDetail->terminal),
                                'scanDate2' => date('Y-m-d H:i:s'),
                                'scanUser2' => $scanUserName,
                                  'substation1' => str_replace('_', ' ', $loginDetail->terminal),
                                'scanDate1' => date('Y-m-d H:i:s'),
                                'scanUser1' => $scanUserName
                            ]);  
                            $response = [
                             // 'heading' => 'Welcome to ' . $locationName->name . ' Ropeways',
                                'tickets' => 'No of Tickets: ' . $ttkt,
                                'firstScan' => [
                                    'scanDate' => $order->scanDate1,
                                    'userName' => $order->scanUser1,
                                    'stationName' => $order->substation1
                                ],
                                'secondScan' => [
                                    'scanDate' => $order->scanDate2,
                                    'userName' => $order->scanUser2,
                                    'stationName' => $order->substation2
                                ],
                                'scanStatus' => $order->scanCount .' Scan: Done', 
                                'stationName' => str_replace('_', ' ', $loginDetail->terminal),
                                'scanDateTime' => date('d-m-Y H:i:s'),
                                'message' => 'You are allowed to avail the service',
                                'status' => true,
                            ];	  
                                  }
                                
                              }
                          
                          }
                        }
                    } else {
                        $response = [
                            'message' => 'ticket scan count not matched!',
                            'status' => false
                        ];
                    }
                }
            else{
                    $response = [
                        'message' => 'tickets are not valid',
                        'status' => false,
                    ];
                }
             }else{
                  $response = [
                        'message' => 'tickets are not found',
                        'status' => false,
                    ];
             }
        } else { 
            $response = [
                'auth-001' => 'Your existing session token does not authorize you anymore',
                'status' => false
            ];
            return response()->json($response, 401);
        }
    
        return response()->json($response, 200);
    }
   
     public function getIdFromQRCode1(Request $request)
    {
        
        $data = Helpers::get_seller_by_token($request);
        $response = [
            'message' => 'An unexpected error occurred.',
            'status' => false
        ];
    
        if ($data['success'] == 1) {
            $seller = $data['data'];
            $loginDetail = DB::table('login_details')
                ->where('vendor_id', $seller->id)
                ->whereNull('logout_time')
                ->latest()
                ->first();
    
            if ($loginDetail) {
                $scanUserName = $seller->f_name . ' ' . $seller->l_name;
                $locationName = DB::table('locations')->where('id', $loginDetail->location_id)->first();
                date_default_timezone_set('Asia/Kolkata');
    
                $orderDetails = OrderDetail::where('order_format', $request->order_id)->get();
                $ttkt = $orderDetails->sum('qty');
    
                $order = Order::where(['order_format'=> $request->order_id,'location_id'=>$loginDetail->location_id])->first();
                //dd($order,$loginDetail);    
                if ($order) { 
                    $d = DB::table('order_details')
                    ->join('ticket_categories','ticket_categories.id', '=',  'order_details.product_id')
                   // ->join('ticket_category_lists', 'ticket_category_lists.id', '=', 'ticket_categories.category_id')
                    ->where('order_details.order_format', $request->order_id)   
                    ->first();
                     
                // dd($d);
                

                //   $scan=  DB::table('ticket_categories')
                //   ->join('ticket_category_lists','ticket_category_lists.id','=','ticket_categories.category_id')
                //   ->where('ticket_categories.id',$d->product_id)->get();
                //   dd($scan);
                    // $scanCount = (int)json_decode($d->product_details, true)['scanCount'];
    
                    if ($order->scanCount === null && $d->scanCount == 1) {
                        Order::where('order_format', $request->order_id)->update([
                            'scanCount' => 1,
                            'substation1' => str_replace('_', ' ', $loginDetail->terminal),
                            'scanDate1' => now(),
                            'scanUser1' => $scanUserName
                        ]);
                        $response = [
                            'heading' => 'Welcome to ' . $locationName->name . ' Ropeways',
                            'tickets' => 'No of Tickets: ' . $ttkt,
                            'scanStatus' => 'First Scan: Done',
                            'stationName' => str_replace('_', ' ', $loginDetail->terminal),
                            'scanDateTime' => date('d-m-Y H:i:s'),
                            'message' => 'You are allowed to avail the service',
                            'status' => true,
                        ];
                    } 
                    // else if ($order->scanCount === null && $d->scanCount > 2) {
                    //     Order::where('order_format', $request->order_id)->update([
                    //         'scanCount' => 1,
                    //         'substation1' => str_replace('_', ' ', $loginDetail->terminal),
                    //         'scanDate1' => now(),
                    //         'scanUser1' => $scanUserName
                    //     ]);
                    //     $response = [
                    //         'heading' => 'Welcome to ' . $locationName->name . ' Ropeways',
                    //         'tickets' => 'No of Tickets: ' . $ttkt,
                    //         'scanStatus' => 'First Scan: Done',
                    //         'stationName' => str_replace('_', ' ', $loginDetail->terminal),
                    //         'scanDateTime' => date('d-m-Y H:i:s'),
                    //         'message' => 'You are allowed to avail the service',
                    //         'status' => true,
                    //     ]; 
                    // }
                    elseif ($order->scanCount === 1 && $d->scanCount == 1) {
                        $response = [
                            'tickets' => 'No of Tickets: ' . $ttkt,
                            'firstScan' => [
                                'scanDate' => $order->scanDate1,
                                'userName' => $order->scanUser1,
                                'stationName' => $order->substation1
                            ],
                            'message' => 'You are not allowed to avail the service',
                            'status' => false,
                        ];
                    } elseif ($order->scanCount === null && $d->scanCount == 2) {
                        Order::where('order_format', $request->order_id)->update([
                            'scanCount' => 1,
                            'substation1' => str_replace('_', ' ', $loginDetail->terminal),
                            'scanDate1' => date('Y-m-d H:i:s'),
                            'scanUser1' => $scanUserName
                        ]);
                        $response = [
                            'heading' => 'Welcome to ' . $locationName->name . ' Ropeways',
                            'tickets' => 'No of Tickets: ' . $ttkt,
                            'scanStatus' => 'First Scan: Done',
                            'stationName' => str_replace('_', ' ', $loginDetail->terminal),
                            'scanDateTime' => date('d-m-Y H:i:s'),
                            'message' => 'You are allowed to avail the service',
                            'status' => true,
                        ];
                    } elseif ($order->scanCount === 1 && $d->scanCount == 2) {
                        Order::where('order_format', $request->order_id)->update([
                            'scanCount' => 2,
                            'substation2' => str_replace('_', ' ', $loginDetail->terminal),
                            'scanDate2' => date('Y-m-d H:i:s'),
                            'scanUser2' => $scanUserName
                        ]);
                        $response = [
                            'heading' => 'Welcome to ' . $locationName->name . ' Ropeways',
                            'tickets' => 'No of Tickets: ' . $ttkt,
                            'firstScan' => [
                                'scanDate' => $order->scanDate1,
                                'userName' => $order->scanUser1,
                                'stationName' => $order->substation1
                            ],
                            'scanStatus' => 'Second Scan: Done',
                            'message' => 'You are allowed to avail the service',
                            'status' => true,
                        ];
                    } elseif ($order->scanCount === 2 && $d->scanCount == 2) {
                        $response = [
                            'tickets' => 'No of Tickets: ' . $ttkt,
                            'firstScan' => [
                                'scanDate' => $order->scanDate1,
                                'userName' => $order->scanUser1,
                                'stationName' => $order->substation1
                            ],
                            'secondScan' => [
                                'scanDate' => $order->scanDate2,
                                'userName' => $order->scanUser2,
                                'stationName' => $order->substation2
                            ],
                            'message' => 'You are not allowed to avail the service',
                            'status' => false,
                        ];
                    }
                } else {
                    $response = [
                        'message' => 'There is no record of the ticket at this location.',
                        'status' => false
                    ];
                }
            } else {
                $response = [
                    'message' => 'Login details not found',
                    'status' => false
                ];
            }
        } else {
            $response = [
                'auth-001' => translate('Your existing session token does not authorize you anymore'),
                'status' => false
            ];
            return response()->json($response, 401);
        }
    
        return response()->json($response, 200);
    }
     
}
?>
