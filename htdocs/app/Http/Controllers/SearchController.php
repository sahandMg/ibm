<?php

namespace App\Http\Controllers;



use App\Jobs\GetPrice;
use App\Product;
use App\Repository\ColumnCode;
use App\Repository\FilterContent;
use App\SubCategory;
use App\Underlay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Debug\Exception\FatalThrowableError;


class SearchController extends Controller
{
    public $type;
    public $paginate;
    public $skip = 20;
    public $shopResp = null;
    public $ColsCode;
    public $newFilter;

    /*
     *  Required Prams => keyword
     *  Optional Prams for search => category , num
     *  Required Prams for filter => filter , category
     *  Required Params for sort => order ,category ,num
     */
    public function SearchPartComp(ColumnCode $code,Request $request)
    {

// ------------ Finding the part in database without filter --------------

        $keyword = $request->keyword;
        $category = $request->category;
        if(is_null($request->num)){
            $this->paginate = 1;
        }else{

            $this->paginate = $request->num;
        }
        //        Searching Between Products
        $product = DB::table('products')->where('product_name', 'like', "%$keyword%")
            ->join('components', 'components.product_id', '=', 'products.id')
            ->skip(($this->skip * ($this->paginate -1)) )->take($this->skip)->get();

        if ($product->count() > 0) {
            $this->type = '10';
            return [$this->type, $product];
        }
        //        Searching Between Components


        $component = DB::table('components')->where('name', 'like', "%$keyword%")->get();

        if ( !$component->isEmpty()) {

            for ($i = 0; $i < count($component); $i++) {

                $cName = $component[$i]->slug;
                $cName = str_replace('-', '_', $cName);
                // Danger!! Add all parts (IC,Connector,...) to App\IC directory
                $models[$i] = 'App\IC\\' . $cName;
                // WARNING! : if the created model haven't been added to App\IC, an exception error will appears
                try{
                    $models[$i] = new $models[$i]();

                }catch (\Throwable $exception){
                   return 'Model is not defined in the App directory';
                }
            }
            if (!isset($models)) {

                return 420;
            }

            $models = array_unique($models);
            $models = array_values($models);

            if (count($models) == 1) {
                $models = $models[0];
                $components = DB::table('components')->where('slug', 'like', "%$keyword%")
                    ->join('commons', 'commons.component_id', '=', 'components.id')
                    ->join($models->getTable(), $models->getTable() . '.' . 'common_id', '=', 'commons.id')
                    ->join('persian_names', 'persian_names.component_id', '=', 'components.id')
                    ->skip(($this->skip * ($this->paginate - 1)))->take($this->skip)->get();
//


                if (isset($components) && $components->count() > 0) {

                    $filters = FilterContent::Filters($models, $components);
                    $this->type = '20';
                    return [$this->type, $components, $filters];
                } else {

                    return '415';
                }

            }
        }
        /**
         *        Searching Between Parts
         *      TODO Check search_part_comp pagination
         **/
        $part = DB::table('commons')
//            ->where('part_number', 'like', "%$keyword%")
            ->orWhere('manufacturer_part_number', 'like', "%$keyword%")
            ->orWhere('manufacturer', 'like', "%$keyword%")
            ->orWhere('description', 'like', "%$keyword%")
            ->skip(($this->skip * ($this->paginate -1)) )->take($this->skip)->get();
        if ($part->isEmpty()) {
            $query = DB::table('failed_parts')->where('manufacturer_part_number',$keyword)->first();
            if($query != null){
                DB::table('failed_parts')->where('manufacturer_part_number',$keyword)->update(['repeat'=>$query->repeat+1]);
            }else{
                DB::table('failed_parts')->insert(['manufacturer_part_number'=>$keyword,'repeat'=>1]);
            }
            return 415;
        } else {
                $c = null;
            for ($i = 0; $i < count($part); $i++) {
                $table = DB::table('components')->where('id', $part[$i]->component_id)->first();
                if($table === null){
                    return 410;
                }
                $cName[$i] = $table->slug;
                $temp = DB::table('components')->where('components.id', $part[$i]->component_id)
                    ->join('products','products.id','=','components.product_id')->first();

                if($temp == null){
                    return 410;
                }
//                $cName2[str_replace('-', 'ـ', $cName[$i])] = $temp->persian_name;
//                $cName2["product$i"] = $temp->product_name;
                if(isset($cName2) && count($cName2)>0){

                        if($temp->slug == $cName2[$c]['category']){
                            array_push($cName2[$c]['subcategory'],str_replace('-', 'ـ', $cName[$i]));
                            $cName2[$c]['subcategory'] = array_unique($cName2[$c]['subcategory']);
                        }else{

                            $cName2[$i] = ['category'=>$temp->slug,'subcategory'=>[str_replace('-', '_', $cName[$i])]];
                            $c = $i;
                        }
                    }else{

                        $cName2[$i] = ['category'=>$temp->slug,'subcategory'=>[str_replace('-', '_', $cName[$i])]];
                        $c = $i;
                    }
                    if($category != 'all' && count($cName2) != 1){
                       if($cName2[$c]['category'] == $category  && count($cName2[$c]['subcategory']) == 1){
                           $cName = str_replace('-', '_', $cName2[$i]['subcategory'])[0];
                           $str = 'App\IC\\'.$cName;
                           $models[$i] = new $str();
                       }

                    }else{
                        if($request->has('subcategory')){
                            $models[$i] = 'App\IC\\' . $request->subcategory;
                            $models[$i] = new $models[$i]();
                        }else{
                            $cName[$i] = str_replace('-', '_', $cName[$i]);
                            $models[$i] = 'App\IC\\' . $cName[$i];
                            $models[$i] = new $models[$i]();
                        }


                    }


            }
            if (!isset($cName) && !isset($models)) {
                return 420;
            }
            $models = collect($models)->unique();
            $models = $models->values();
            if (count($models) == 1) {
                $models = $models [0];
                $parts = DB::table('commons')
//                    ->where('part_number', 'like', "%$keyword%")
                    ->orWhere('manufacturer_part_number', 'like', "%$keyword%")
                    ->orWhere('manufacturer', 'like', "%$keyword%")
                    ->orWhere('description', 'like', "%$keyword%")
                    ->join('components', 'commons.component_id', '=', 'components.id')
//                    ->join('persian_names', 'persian_names.component_id', '=', 'components.id')
                    ->join($models->getTable(), $models->getTable() . '.' . 'common_id', '=', 'commons.id')
                    ->skip(($this->skip * ($this->paginate - 1)))->take($this->skip)->get();
                $names = $parts->pluck('names')->toArray();
                $tableCols = Schema::getColumnListing($models->getTable());
                array_shift($tableCols);
                array_pop($tableCols);
                array_pop($tableCols);
                array_pop($tableCols);
                array_pop($tableCols);
                if (isset($names) && count($names) > 0) {
                    $names = array_unique($names);
                    $names = unserialize($names[0]);
//
                }

                if (!isset($parts) || $parts->isEmpty()) {

                    return 415;
                } else {
                    $filters = FilterContent::Filters($models,$parts);
                    $columns = $code->sendFilter($filters);
                    //        ----------------  Finding the part in websites  -------------------
                    //            $crawled = new GetSiteContent();
                    //            $crawlers = $crawled->getSite($keyword);
                    //            if ($crawlers == 0) {
                    //                return 435;
                    //            }
                    //        Sending keyword and crawled response to crawlSite method in shop directory classes
                    //            for ($i = 0; $i < count($crawlers); $i++) {
                    //                $className = array_keys(GetSiteContent::$website)[$i];
                    //                $list[$i] = $className::crawlSite($crawlers[$i], $keyword);
                    //            }
                    //            if (isset($list)) {
                    //                $this->type = '30';
                    //                return [$this->type, $parts,$filters];
                    //
                    //            } else {
                    //                return 420;
                    //            }
                    $this->type = '30';
                    GetPrice::dispatch($keyword)->delay(2);
//                        -----------------------------
                    /*
                    * Create a breadcrumb
                    */
                    $breadCrumb = DB::table('components')->where('components.slug',$parts[0]->slug)
                        ->join('products','components.product_id','=','products.id')->select('name','product_name')->get();
                    $breadCrumb = $breadCrumb[0];
//                -------> Running queued job <------
//                        Artisan::queue('queue:work',["--once"=>true]);

                    if($request->has('filters') && $request->has('category')){
                        $result = $this->filterPart($request,$code,$keyword,$this->paginate);
                        if($result == 404){
                            return 'Incorrect Filter Name';
                        }
                        return ([$this->type,$this->shopResp,$result,$this->newFilter,$names,$this->ColsCode,$breadCrumb]);
                    }
                    else if($request->has('order') && $request->has('colName')){

                        $parts = $this->sort($request->all(),$parts);
                        $parts = $this->unsetPart($parts);
                        return [$this->type,$this->shopResp ,$parts, $filters, $names ,$columns,$breadCrumb];
//                        return [$this->type,$this->shopResp ,$parts, $filters, $names ,$columns,$breadCrumb];
                    }
                    $parts = $this->unsetPart($parts);
                    return [$this->type,$this->shopResp ,$parts, $filters, $names ,$columns,$breadCrumb];



                }
            }else{
                return array_values($cName2);
            }
        }
    }
    /*
     * Deletes unnecessary data from search result
     */
    private function unsetPart($parts){

        for($t=0;$t<count($parts);$t++){
            unset(
                $parts[$t]->names,
                $parts[$t]->id,
                $parts[$t]->component_id,
                $parts[$t]->common_id,
                $parts[$t]->links,
                $parts[$t]->product_id,
                $parts[$t]->part_id,
                $parts[$t]->model,
                $parts[$t]->created_at,
                $parts[$t]->updated_at
            );
        }
        return $parts;
    }

    /**
     * @param Request $request
     *  $filters = [
     * //
     * //            'speed' => ['40MHz'],
     * ////            'packaging'=>['Tray  Alternate Packaging'],
     * //            'manufacturer'=>['Microchip Technology'],
     * ////        'voltage_supply_digital' => ['2 V ~ 5.5 V']
     * //
     * //        ];
     * @param ColumnCode $code
     * @param $keyword
     * @param int $num
     * @return array|string
     */
    public function filterPart($request, $code,$keyword, $num){
//

        /**
         * TODO remove part_number unit_price quantity from filters
         */
//        $filters = [
//            'rCl' => ['40MHz'],
//            'tra'=>['Microchip Technology'],
//                    ];
//                $component = 'Embedded-Microcontrollers';

        $filters = $request->filters;
        $component = $request->category;
            $pagination = $num;
        /*
         * convert json to array
         */
        $filters = json_decode($filters,true);

        /*
        *  Decoding filter array keys
        */
        $filters = $code->getFilter($filters);

        if($filters == 404){

            return 404;
        }

        $component = DB::table('components')->where('slug','like',"%$component%")->first();

        if($component == null ){
            return 410;
        }
//        Create class path from class string name --> App\IC\PMIC_Display_Drivers
        $class = 'App\IC\\'.str_replace('-','_',$component->slug);
//        Class Name --> PMIC_Display_Drivers
        $className = str_replace('-','_',$component->slug);
        $model = new $class();
        $commonTableCols = Schema::getColumnListing('commons');

//        Gets related model table name --> create__pmic_display_drivers__table
        $sepTableCols = Schema::getColumnListing($model->getTable());


//        $common = DB::table('commons')->get();
        $common = DB::table('commons')
//            ->where('part_number', 'like', "%$keyword%")
            ->orWhere('manufacturer_part_number', 'like', "%$keyword%")
            ->orWhere('manufacturer', 'like', "%$keyword%")
            ->orWhere('description', 'like', "%$keyword%")->get();

        $separate = DB::table($model->getTable())->get();
        $cFlag = [];
        $sFlag = [];
//    $result = [];
//    $ids = [];

        for($i=0 ; $i < count($commonTableCols) ; $i++){
            for($t=0 ; $t<count($filters);$t++) {
//  Checking filter keys with common table column names to findout whether the common table needs to be filtered or not
                similar_text(array_keys($filters)[$t], $commonTableCols[$i], $percent);
                if ($percent >= 80) {
                    array_push($cFlag, array_keys($filters)[$t]);
                    $cFlag = array_unique($cFlag);
                    $cFlag = array_values($cFlag);
                }
            }
        }
        for($i=0 ; $i < count($sepTableCols) ; $i++){
            for($t=0 ; $t<count($filters);$t++) {
//  Checking filter keys with separate tables column names to findout whether the tables need to be filtered or not
                similar_text(array_keys($filters)[$t], $sepTableCols[$i], $percent);
                if($percent >= 80){
                    array_push($sFlag, array_keys($filters)[$t]);
                    $sFlag = array_unique($sFlag);
                    $sFlag = array_values($sFlag);
                }
            }
        }

        if($cFlag){

            for($i=0;$i<count($cFlag);$i++) {

                if(count($filters[$cFlag[$i]]) > 1) {
                    for ($j = 0; $j < count($filters[$cFlag[$i]]); $j++) {

                        if ($j == count($filters[$cFlag[$i]]) - 1) {
                            break;
                        }

                        $common = $common->whereIn($cFlag[$i], [$filters[$cFlag[$i]][$j], $filters[$cFlag[$i]][$j + 1]])
                            ->where('model',str_replace('-',' ',$component->slug));
                    }
                }else{

//                for($i=0;$i<count($cFlag);$i++) {
                    for ($j = 0; $j < count($filters[$cFlag[$i]]); $j++) {
                        $common = $common->where($cFlag[$i], $filters[$cFlag[$i]][$j])->where('model',str_replace('-',' ',$component->slug));
                    }

                }
            }

            $common = array_values($common->all());

            for($i=0;$i<count($common);$i++){

                $ids[$i] = $common[$i]->id;

            }
        }
        if($sFlag){
            for($i=0;$i<count($sFlag);$i++) {

                if(count($filters[$sFlag[$i]]) > 1){

                    for ($j = 0; $j < count($filters[$sFlag[$i]]); $j++) {

                        if ($j == count($filters[$sFlag[$i]]) - 1) {
                            break;
                        }
                        $separate = $separate->whereIn($sFlag[$i], [$filters[$sFlag[$i]][$j],$filters[$sFlag[$i]][$j+1]]);
                    }
                }else{
                    for ($j = 0; $j < count($filters[$sFlag[$i]]); $j++) {
                        $separate = $separate->where($sFlag[$i], $filters[$sFlag[$i]][$j]);
                    }
                }

            }
            $separate = array_values($separate->all());
            for($i=0;$i<count($separate);$i++){

                $result[$i] = DB::table('commons')->where('id',$separate[$i]->common_id)->first()->id;

            }
        }

        if (isset($ids)  && isset($result)) {
            $sameIds = array_intersect($result, $ids);

        }elseif(isset($ids) && $sFlag == null){

            $sameIds = $ids;
        }elseif(isset($result) && $cFlag == null){
            $sameIds = $result;
        }else{

            return 415;
        }
        $sameIds = array_values($sameIds);

        for ($i = 0; $i < count($sameIds); $i++) {
//
//            $parts[$i] = Common::where('id', $sameIds[$i])->with($className)->first();

            $parts[$i] =DB::table('commons')->where('commons.id', $sameIds[$i])
                ->join($model->getTable(),'commons.id','=',$model->getTable().'.'.'common_id')
                ->first();
        }
        if (!isset($parts)) {
            return '415';
        } else {

//ساخت مجدد محتوای فیلترها بر اساس نتیجه جستجو شده

            $commonTableCols = Schema::getColumnListing('commons');

            $sepTableCols = Schema::getColumnListing($model->getTable());

            array_shift($sepTableCols);
            array_pop($sepTableCols);
            array_pop($sepTableCols);
            array_pop($sepTableCols);
            array_pop($sepTableCols);
            array_shift($commonTableCols);
            array_shift($commonTableCols);
            array_shift($commonTableCols);
            array_shift($commonTableCols);
            array_shift($commonTableCols);
            array_pop($commonTableCols);
            array_pop($commonTableCols);
            array_pop($commonTableCols);
            array_pop($commonTableCols);
            array_pop($commonTableCols);

            for ($t = 0; $t < count($commonTableCols); $t++) {

                if( count($parts) - 20*($pagination-1) > 20 ){
                   $endPoint = 20*($pagination);
               }else if( count($parts) - 20*($pagination-1) > 0){
                   $endPoint = count($parts);
               }else{
                   return 415;
               }
                for ($i = 20*($pagination-1); $i < $endPoint ; $i++) {
                    $colName = $commonTableCols[$t];
                    $cols[$commonTableCols[$t]][$i] = $parts[$i]->$colName;
                    $cols[$commonTableCols[$t]] = array_unique($cols[$commonTableCols[$t]]);
                    $cols[$commonTableCols[$t]] = array_values($cols[$commonTableCols[$t]]);
                }

                if(count($cols[$commonTableCols[$t]]) == 1){
                    unset($cols[$commonTableCols[$t]]);
                }
            }
            for ($t = 0; $t < count($sepTableCols); $t++) {
                if( count($parts) - 20*($pagination-1) > 20 ){
                    $endPoint = 20*($pagination);
                }else if( count($parts) - 20*($pagination-1) > 0){
                    $endPoint = count($parts);
                }else{
                    return 415;
                }
                for ($i = 0; $i < $endPoint; $i++) {
                    $colName = $sepTableCols[$t];
                    $sepCols[$sepTableCols[$t]][$i] = $parts[$i]->$colName;
                    $sepCols[$sepTableCols[$t]] = array_unique($sepCols[$sepTableCols[$t]]);
                    $sepCols[$sepTableCols[$t]] = array_values($sepCols[$sepTableCols[$t]]);
                }
                if(count($sepCols[$sepTableCols[$t]]) == 1){
                    unset($sepCols[$sepTableCols[$t]]);
                }
            }

            if (!isset($cols) || !isset($sepCols)) {
                return '420';
            } else {
                $ColsCode = $code->sendFilter(array_merge($cols,$sepCols));
                $result = array_merge($cols,$sepCols);
                $this->ColsCode = $ColsCode;
                $this->newFilter = $result;
                unset($this->newFilter['unit_price']);
                unset($this->newFilter['quantity_available']);
                $parts = array_slice($parts,20*($pagination-1),20);
                $parts = $this->unsetPart($parts);
                return $parts;
            }
        }
    }


    /**
     * @param Request $request
     * @return \Illuminate\Support\Collection|string
     * gets parts price from database
     * TODO check this api
     */
    public function getPrice(Request $request){

        if(!$request->has('keyword')){
            return 'send a keyword';
        }

        $price = DB::table('commons')->where('manufacturer_part_number', $request->keyword)
            ->select('manufacturer_part_number','unit_price')->first();
        if($price == null){
            return 415;
        }

        return json_encode($price);

    }

//    Get num,name
// check if there is much of it or not
    public function getQuantity(Request $request){

        $number = DB::table('commons')->where('manufacturer_part_number',$request->name)->first()->quantity_available;

        if($number < $request->num){

            return ' از تعداد موجود بیشتر است '.$request->name.' تعداد خواسته شده از ';
        }else{
            return 200;
        }

    }

    public function SearchPart(Request $request){
        $keyword = $request->keyword;
        $product = DB::table('products')->where('product_name', 'like', "%$keyword%")
            ->join('components', 'components.product_id', '=', 'products.id')->get();

        if(!$product->isEmpty()){
            $this->type = '10';
            return [$this->type,$product];
        }


        $component = DB::table('components')->where('name', 'like', "%$keyword%")
            ->join('commons', 'commons.component_id', '=', 'components.id')
            ->get();

        if(count($component) > 0){
            $this->type = '20';
            return [$this->type,$component,];

        }

        $part = DB::table('commons')
//            ->where('part_number', 'like', "%$keyword%")
            ->orWhere('manufacturer_part_number', 'like', "%$keyword%")
            ->orWhere('manufacturer', 'like', "%$keyword%")
            ->orWhere('description', 'like', "%$keyword%")
            ->join('components', 'commons.component_id', '=', 'components.id')
            ->get()->take(5);

        if ($part->isEmpty()) {

            return 415;
        } else {
            for($t=0;$t<count($part);$t++){
                unset(
                    $part[$t]->names,
                    $part[$t]->id,
                    $part[$t]->component_id,
                    $part[$t]->common_id,
                    $part[$t]->links,
                    $part[$t]->product_id,
                    $part[$t]->model,
                    $part[$t]->created_at,
                    $part[$t]->updated_at
                );
            }
            $this->type = '30';
            return [$this->type,$part];
        }
    }

    public function findArticle(Request $request){
        $keyword = $request->keyword;
        $briefs = DB::table('briefs')->where('title','like',"%$keyword%")
            ->orWhere('abstract','like',"%$keyword%")
            ->orWhere('category','like',"%$keyword%")
            ->orWhere('product','like',"%$keyword%")
            ->join('details','details.brief_id','briefs.id')->get();
        if($briefs->isEmpty()){
            $content = DB::table('details')->where('text','like',"%$keyword%")
                ->join('briefs','details.brief_id','briefs.id')->get();
            if($content->isEmpty()){
                return 417;
            }else{
                return $content;
            }
        }else{

            if($briefs->count() > 0 && $briefs->pluck('category')->unique()->count() > 1){
                return $briefs->pluck('category');

            }else{

                return $briefs;
            }
        }

    }

//    Gets component,num(item per page),order(desc or asc),colName
    public function sort($request,$parts)
    {

//        باید دیتا ها رو ۲۰ تا ۲۰ تا سورت کنی
        /*
         *  use two methods to sort columns
         *  1) commonSort -> sort common table columns
         *  2) separateSort -> sort separate table columns
         */
        $class = 'App\IC\\'.$request['category'];

        $order = $request['order'];
        $model = new $class();
        $colName = $request['colName'];
        $commonCols = Schema::getColumnListing('commons') ;
        $sepCols = Schema::getColumnListing($model->getTable());
        if(array_search($colName,$commonCols)){
            return $this->commonSort($request,$parts);
        }elseif (array_search($colName,$sepCols)){
            return $this->separateSort($request,$parts);
        }else{
            return 'column not found';
        }


    }

    private function separateSort($request,$parts){

        $rawModel = $request['category'];
        str_replace(' ','_',$rawModel);
        $class = 'App\IC\\'.$rawModel;

        $order = $request['order'];
        $model = new $class();
        $colName = $request['colName'];;
        $component = $parts;
        if($component == null){
            return '410';
        }else{

            for($i=0 ; $i < count($component) ; $i++){

                $cols[$component[$i]->id] = $component[$i]->$colName;
                $cols[$component[$i]->id] = str_replace('~','',$cols[$component[$i]->id]);
                $str[$component[$i]->id] = explode(" ",$cols[$component[$i]->id])[0];
                $volts[$component[$i]->id]  = preg_replace('/[^-0-9\.]/', '',$str[$component[$i]->id]);
                /*
                 * µ , n , m , k , K , M , G
                */

                if(strpbrk($str[$component[$i]->id],'n')){
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] /pow(10,9);
                }elseif(strpbrk($str[$component[$i]->id],'µ')){
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] /pow(10,6);
                }elseif(strpbrk($str[$component[$i]->id],'m')){
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] /pow(10,3);
                }elseif(strpbrk($str[$component[$i]->id],'k')){
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] * pow(10,3);
                }elseif(strpbrk($str[$component[$i]->id],'K')){
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] * pow(10,3);
                }
                elseif(strpbrk($str[$component[$i]->id],'M')){
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] * pow(10,6);
                }elseif(strpbrk($str[$component[$i]->id],'G')) {
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] * pow(10,9);
                }
            }

//            $volts = not common table Ids
            if($order == 'desc'){

                arsort($volts);
            }else{
                asort($volts);
            }
            $newVolts = array_keys($volts);
//            $newVolts = array_slice($volts,20*($paginate-1),20);
            for($i=0 ;$i<count($newVolts);$i++){
                $common_id = DB::table($model->getTable())
                    ->where($model->getTable().'.'.'id','=',$newVolts[$i])
                    ->first()->common_id;
                $rows[$i] = DB::table('commons')->where('commons.id',$common_id)
                    ->join($model->getTable(),'commons.id','=',$model->getTable().'.'.'common_id')
                    ->first();
            }
            if(isset($rows)){
                return $rows;
            }else{
                return 420;
            }
        }
    }

    private function commonSort($request,$parts){

        $rawModel = $request['category'];
        str_replace(' ','_',$rawModel);
        $class = 'App\IC\\'.$rawModel;

        $order = $request['order'];
        $model = new $class();
        $colName = $request['colName'];
//        $component = DB::table('commons')->where('model','like',"%$request->component%")->get();
        $component = $parts;

        if($component == null){
            return '410';
        }else{

            for($i=0 ; $i < count($component) ; $i++){

                $cols[$component[$i]->id] = $component[$i]->$colName;
                $cols[$component[$i]->id] = str_replace('~','',$cols[$component[$i]->id]);
                $str[$component[$i]->id] = explode(" ",$cols[$component[$i]->id])[0];
                $volts[$component[$i]->id]  = preg_replace('/[^-0-9\.]/', '',$str[$component[$i]->id]);
                /*
                 * µ , n , m , k , K , M , G
                */
                if(strpbrk($str[$component[$i]->id],'n')){
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] /pow(10,9);
                }elseif(strpbrk($str[$component[$i]->id],'µ')){
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] /pow(10,6);
                }elseif(strpbrk($str[$component[$i]->id],'m')){
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] /pow(10,3);
                }elseif(strpbrk($str[$component[$i]->id],'k')){
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] * pow(10,3);
                }elseif(strpbrk($str[$component[$i]->id],'K')){
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] * pow(10,3);
                }
                elseif(strpbrk($str[$component[$i]->id],'M')){
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] * pow(10,6);
                }elseif(strpbrk($str[$component[$i]->id],'G')) {
                    $volts[$component[$i]->id] = $volts[$component[$i]->id] * pow(10,9);
                }
            }

//            $volts = not common table Ids
            if($order == 'desc'){

                arsort($volts);
            }else{
                asort($volts);
            }

            $newVolts = array_keys($volts);
//            $newVolts = array_slice($volts,20*($paginate-1),20);

            for($i=0 ;$i<count($newVolts);$i++){
                $common_id = DB::table($model->getTable())
                    ->where($model->getTable().'.'.'id','=',$newVolts[$i])
                    ->first()->common_id;
                $rows[$i] = DB::table('commons')->where('commons.id',$common_id)
                    ->join($model->getTable(),'commons.id','=',$model->getTable().'.'.'common_id')
                    ->first();
            }

            if(isset($rows)){
                return $rows;
            }else{
                return 420;
            }
        }
    }
/*
 * Required Params => nothing
 * return main menu categories
 */
    public function subMenu(){
       $menu = [];
        $products = Product::all();
        $subCategories = SubCategory::all();
        foreach ($products as $key => $product){
           $menu[$key]['product'] = $product->product_name;
           $menu[$key]['category'] = $product->subcategories->pluck('name')->toArray();
            $subCategories = $product->subcategories;
//            foreach ($subCategories as $item => $subCategory) {
//                $menu[$key]['category']['subcategory'] = $subCategory->underlays->pluck('name')->toArray();
//            }

        }
        return ($menu);
//        $products = DB::table('products')->get();
//        $subcategories = DB::table('sub_categories')->get();
//        $underlays = DB::table('underlays')->get();
//        foreach ($products as $key => $product) {
//            foreach ($subcategories as $item => $subcategory) {
//                foreach ($underlays as  $underlay) {
//                    $menu[$key]['product'] = $product->product_name;
//                    $menu[$key]['category'] = DB::table('sub_categories')
//                        ->where('product_id', $product->id)->get()->pluck('name')->toArray();
//                    $menu[$key]['underlay'] = DB::table('underlays')
//                    ->where('subcategory_id',$subcategory->id)->get()->pluck('name')->toArray();
//                }
//            }
//        }

    }
//subcategory
    //TODO create an object of all components with their category
    // TODO create an api for checking part quantity when clicking on buy button
}