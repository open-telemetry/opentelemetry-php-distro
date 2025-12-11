#pragma once

#define STRINGIFY_HELPER_IMPL( token ) #token
#define STRINGIFY_HELPER( token ) STRINGIFY_HELPER_IMPL( token )
