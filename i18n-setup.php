<?php
// Place this file in your plugin root directory

function scan_php_files($dir) {
    $result = array();
    $scan = scandir($dir);
    
    foreach ($scan as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        
        if (is_dir($path)) {
            $result = array_merge($result, scan_php_files($path));
        } else if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            $result[] = $path;
        }
    }
    
    return $result;
}

function check_translation_functions($files) {
    $patterns = array(
        '/__\s*\(\s*[\'"](.*?)[\'"],\s*[\'"]?([a-zA-Z0-9-_]+)?[\'"]?\s*\)/',           // __() function
        '/_e\s*\(\s*[\'"](.*?)[\'"],\s*[\'"]?([a-zA-Z0-9-_]+)?[\'"]?\s*\)/',           // _e() function
        '/esc_html__\s*\(\s*[\'"](.*?)[\'"],\s*[\'"]?([a-zA-Z0-9-_]+)?[\'"]?\s*\)/',   // esc_html__() function
        '/esc_attr__\s*\(\s*[\'"](.*?)[\'"],\s*[\'"]?([a-zA-Z0-9-_]+)?[\'"]?\s*\)/',   // esc_attr__() function
        '/esc_html_e\s*\(\s*[\'"](.*?)[\'"],\s*[\'"]?([a-zA-Z0-9-_]+)?[\'"]?\s*\)/',   // esc_html_e() function
        '/esc_attr_e\s*\(\s*[\'"](.*?)[\'"],\s*[\'"]?([a-zA-Z0-9-_]+)?[\'"]?\s*\)/'    // esc_attr_e() function
    );
    
    $missing_domain = array();
    
    foreach ($files as $file) {
        $content = file_get_contents($file);
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            
            if (!empty($matches[0])) {
                foreach ($matches[0] as $key => $match) {
                    if (empty($matches[2][$key]) || $matches[2][$key] !== 'hyperswitch') {
                        $missing_domain[] = array(
                            'file' => $file,
                            'string' => $match
                        );
                    }
                }
            }
        }
    }
    
    return $missing_domain;
}

// Scan files
$files = scan_php_files(__DIR__);
$missing = check_translation_functions($files);

if (!empty($missing)) {
    echo "Found translation functions missing 'hyperswitch' text domain:\n\n";
    foreach ($missing as $item) {
        echo "File: {$item['file']}\n";
        echo "Function: {$item['string']}\n\n";
    }
} else {
    echo "All translation functions have proper text domain.\n";
}
