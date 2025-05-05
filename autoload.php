<?php
foreach (scandir("src") as $file){
    if (in_array($file, ['.','..'])){continue;}
    require_once("src/$file");
}