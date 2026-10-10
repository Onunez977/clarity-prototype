<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('app.env') !== 'production') {
            try {
                DB::connection('zod')->unprepared('
CREATE OR REPLACE FUNCTION public.get_license_plate_variants(input_text TEXT)
    RETURNS TEXT[] AS $$
DECLARE
    char_mappings JSONB := \'{
        "0": ["0", "O", "D", "Q"],
        "1": ["1", "I", "L"],
        "8": ["8", "B"],
        "B": ["B", "8"],
        "D": ["D", "0"],
        "I": ["I", "1", "L"],
        "L": ["L", "I", "1"],
        "O": ["O", "0", "Q"],
        "Q": ["Q", "O", "0"],
        "S": ["S", "5"],
        "5": ["5", "S"]
    }\';
    result TEXT[] := ARRAY[input_text];
    current_char TEXT;
    alternatives TEXT[];
    new_variants TEXT[];
    variant TEXT;
    alt TEXT;
    i INTEGER;
BEGIN
    FOR i IN 1..length(input_text) LOOP
            current_char := substring(input_text FROM i FOR 1);

            IF char_mappings ? current_char THEN
                alternatives := ARRAY(SELECT jsonb_array_elements_text(char_mappings->current_char));
                new_variants := ARRAY[]::TEXT[];

                FOREACH variant IN ARRAY result LOOP
                        FOREACH alt IN ARRAY alternatives LOOP
                                new_variants := array_append(new_variants,
                                                             overlay(variant PLACING alt FROM i FOR 1));
                            END LOOP;
                    END LOOP;

                result := new_variants;
            END IF;
        END LOOP;

    RETURN array(SELECT DISTINCT unnest(result));
END;
$$ LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE;
        ');
            } catch (Exception $exception) {
                //
            }
        }
    }
};
