<?php

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\Product;
use App\Models\TechnicalInfo;
use App\Services\SitemapService;
use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Facades\Storage;

class ingradData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:ingrads';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        ini_set('memory_limit', '-1');
        ini_set("precision", 2);
        $inits = 1;
        LazyCollection::make(function () {
            $handle = fopen(public_path("foodfacts.csv"), 'r');
            
            while (($line = fgetcsv($handle, 0, "\t")) !== false) {
              $dataString = implode("\t", $line);
              $row = explode("\t", $dataString);
              yield $row;
            }
      
            fclose($handle);
          })
          ->skip(1)
          ->chunk(10000)
          ->each(function (LazyCollection $chunk) use (&$inits) {
            $records = $chunk->map(function ($row) {
              return [
                "code" => $row[0],
                "title" => $row[8],
                "abbr" => $row[9],
                "label" => $row[10],
                "brand" => $row[16],
                "quantity" => $row[11],
                "serving" => $row[47],
                "servingQ" => $row[48],
                "nutrients" => [
                  "energy" => round((double) $row[87], 2),
                  "protein" => round((double) $row[141], 2),
                  "lipidTotal" => round((double) $row[89], 2),
                  "ash" => round((double) $row[190], 2),
                  "carbs" => round((double) $row[126], 2),
                  "fiber" => round((double) $row[138], 2),
                  "sugar" => round((double) $row[127], 2),
                  "sucrose" => round((double) $row[129], 2),
                  "glucose" => round((double) $row[130], 2),
                  "fructose" => round((double) $row[131], 2),
                  "lactose" => round((double) $row[132], 2),
                  "maltose" => round((double) $row[133], 2),
                  "omega" => round((double) $row[108], 2),
                  "pantoAcid" => round((double) $row[163], 2),
                  "folateTotal" => round((double) $row[160], 2),
                  "cholineTotal" => round((double) $row[196], 2),
                  "vitaA" => round((double) $row[149], 2),
                  "vitaB6" => round((double) $row[158], 2),
                  "vitaB12" => round((double) $row[161], 2),
                  "vitaC" => round((double) $row[154], 2),
                  "vitaD" => round((double) $row[151], 2),
                  "vitaE" => round((double) $row[152], 2),
                  "vitaK" => round((double) $row[153], 2),
                  "betaCarot" => round((double) $row[150], 2),
                  "faSat" => round((double) $row[90], 2),
                  "faMono" => round((double) $row[106], 2),
                  "faPoly" => round((double) $row[107], 2),
                  "faUnsat" => round((double) $row[105], 2),
                  "faChole" => round((double) $row[125], 2),
                ],
                "ingredients" => $row[39],
                "additives" => $row[53],
                "scanCode" => $row[72],
                "allergen" => $row[43],
                "nScore" => $row[54],
                "nGrade" => $row[55],
                "embCode" => $row[29],
                "category" => $row[78],
                "group" => $row[61],
                "country" => $row[38],
              ];
            })->toArray();
            
            if(!Storage::disk('public')->put('ingrads/ig_'.$inits.'.json', json_encode($records))) {
              return false;
            }
            $inits++;
          });
    }
 


}
